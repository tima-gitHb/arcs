<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Enums\SegmentDirection;
use App\Exceptions\FareNotFound;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\ReservationSegment;
use App\Models\TripFare;
use Illuminate\Support\Collection;

/**
 * The only place to compute a price. It reads the fares and fees tables, so changing a
 * price there (card M7) is all it takes. Trip fares (B14) override the per-route sum when set.
 * Multi-city (CR-16): one trip fare (or route sum) per leg, added up.
 */
class FareService
{
    private const CURRENCY = 'USD';

    /**
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     * @param  array{extra_bags?: int, extra_kg?: int, fees?: list<FeeCode>}  $extras
     * @param  int  $flights  flights in the journey (B13b): the class/type fare is charged per flight,
     *                        so the journey price is the sum of each segment's fare (SR-69). Extras once.
     * @param  string|null  $origin  with $destination: the route's fare when it has one, else the default
     *                               (SR-69). Without a route: the default fare, as before B13b.
     */
    public function quote(array $passengers, array $extras = [], int $flights = 1, ?string $origin = null, ?string $destination = null): Quote
    {
        $lines = [];
        $flights = max(1, $flights);

        foreach ($this->groupPassengers($passengers) as $group) {
            $lines[] = $this->line(
                "{$group['cabin_class']->label()} {$group['type']->label()} fare".($flights > 1 ? " × {$flights} flights" : ''),
                $group['quantity'] * $flights,
                $this->fareCents($group['cabin_class'], $group['type'], $origin, $destination),
            );
        }

        if (($extras['extra_bags'] ?? 0) > 0) {
            $lines[] = $this->feeLine(FeeCode::ExtraBag, $extras['extra_bags']);
        }

        if (($extras['extra_kg'] ?? 0) > 0) {
            $lines[] = $this->feeLine(FeeCode::ExcessKg, $extras['extra_kg']);
        }

        foreach ($extras['fees'] ?? [] as $code) {
            $lines[] = $this->feeLine($code, 1);
        }

        return new Quote($lines, array_sum(array_column($lines, 'amount_cents')), self::CURRENCY);
    }

    /**
     * One line per class and type, in the order they first appear.
     *
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     * @return list<array{cabin_class: CabinClass, type: PassengerType, quantity: int}>
     */
    private function groupPassengers(array $passengers): array
    {
        $groups = [];

        foreach ($passengers as $passenger) {
            $key = $passenger['cabin_class']->value.'|'.$passenger['type']->value;
            $groups[$key] ??= $passenger + ['quantity' => 0];
            $groups[$key]['quantity']++;
        }

        return array_values($groups);
    }

    /**
     * The price of a journey (B13b / B14 / CR-16, SR-69):
     * - classic one-way / connection: trip fare origin→final outbound dest when set, else per-route sum
     * - round trip: round_trip_cents when set, else one_way trip or per-route sum
     * - multi-city (2+ legs, no return direction): trip fare (or route sum) per leg, added up
     */
    public function journeyQuote(Reservation $reservation): Quote
    {
        $reservation->loadMissing(['segments.flight', 'passengers']);
        $passengers = $reservation->passengers
            ->map(fn (Passenger $passenger) => [
                'cabin_class' => $passenger->cabin_class ?? CabinClass::Economy,
                'type' => $passenger->type,
            ])
            ->all();

        $legs = app(JourneyService::class)->legs($reservation);
        $hasReturn = $reservation->segments->contains(
            fn (ReservationSegment $segment) => $segment->direction === SegmentDirection::Return,
        );

        // Multi-city: every leg is priced on its own (CR-13 / CR-16).
        if ($legs->count() > 1 && ! $hasReturn) {
            return $this->multiCityQuote($legs, $passengers);
        }

        $trip = $this->tripJourneyQuote($reservation, $passengers);
        if ($trip !== null) {
            return $trip;
        }

        return $this->routeSumQuote($reservation->segments, $passengers);
    }

    /**
     * @param  Collection<int, Collection<int, ReservationSegment>>  $legs
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     */
    private function multiCityQuote(Collection $legs, array $passengers): Quote
    {
        $lines = [];

        foreach ($legs as $index => $segments) {
            $origin = $segments->first()->flight->origin;
            $destination = $segments->last()->flight->destination;
            $legNo = $index + 1;

            $trip = $this->tripFareLines($passengers, $origin, $destination, hasReturn: false, labelPrefix: "Leg {$legNo} {$origin}→{$destination}");
            if ($trip !== null) {
                array_push($lines, ...$trip);

                continue;
            }

            foreach ($this->routeSumQuote($segments, $passengers)->lines as $line) {
                $lines[] = ['label' => "Leg {$legNo}: {$line['label']}"] + $line;
            }
        }

        return new Quote($lines, array_sum(array_column($lines, 'amount_cents')), self::CURRENCY);
    }

    /**
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     */
    private function tripJourneyQuote(Reservation $reservation, array $passengers): ?Quote
    {
        $segments = $reservation->segments;
        if ($segments->isEmpty()) {
            return null;
        }

        $outbound = $segments->where('direction', SegmentDirection::Outbound)->values();
        if ($outbound->isEmpty()) {
            $outbound = $segments->values();
        }

        $origin = $outbound->first()->flight->origin;
        $destination = $outbound->last()->flight->destination;
        $hasReturn = $segments->contains(fn ($segment) => $segment->direction === SegmentDirection::Return);

        $lines = $this->tripFareLines($passengers, $origin, $destination, $hasReturn);
        if ($lines === null) {
            return null;
        }

        return new Quote($lines, array_sum(array_column($lines, 'amount_cents')), self::CURRENCY);
    }

    /**
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     * @return list<array{label: string, quantity: int, unit_cents: int, amount_cents: int}>|null
     */
    private function tripFareLines(
        array $passengers,
        string $origin,
        string $destination,
        bool $hasReturn,
        string $labelPrefix = '',
    ): ?array {
        $lines = [];
        $prefix = $labelPrefix !== '' ? $labelPrefix.' ' : "{$origin}→{$destination} ";

        foreach ($this->groupPassengers($passengers) as $group) {
            $tripFare = TripFare::query()
                ->forTrip($origin, $destination)
                ->where('cabin_class', $group['cabin_class'])
                ->where('passenger_type', $group['type'])
                ->first();

            if ($tripFare === null) {
                return null;
            }

            $unit = $hasReturn && $tripFare->round_trip_cents !== null
                ? $tripFare->round_trip_cents
                : $tripFare->one_way_cents;

            $kind = $hasReturn && $tripFare->round_trip_cents !== null ? 'round-trip' : 'trip';

            $lines[] = $this->line(
                "{$prefix}{$kind} {$group['cabin_class']->label()} {$group['type']->label()}",
                $group['quantity'],
                $unit,
            );
        }

        return $lines;
    }

    /**
     * @param  Collection<int, ReservationSegment>  $segments
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     */
    private function routeSumQuote(Collection $segments, array $passengers): Quote
    {
        $lines = [];
        foreach ($segments as $segment) {
            $flight = $segment->flight;

            foreach ($this->quote($passengers, origin: $flight->origin, destination: $flight->destination)->lines as $line) {
                $lines[] = ['label' => "{$flight->origin}→{$flight->destination} {$flight->flight_no}: {$line['label']}"] + $line;
            }
        }

        return new Quote($lines, array_sum(array_column($lines, 'amount_cents')), self::CURRENCY);
    }

    private function fareCents(CabinClass $cabin, PassengerType $type, ?string $origin = null, ?string $destination = null): int
    {
        $fares = Fare::query()->where('cabin_class', $cabin)->where('passenger_type', $type);

        $cents = ($origin !== null && $destination !== null
            ? (clone $fares)->forRoute($origin, $destination)->value('amount_cents')
            : null) ?? $fares->defaults()->value('amount_cents');

        if ($cents === null) {
            throw new FareNotFound(
                "No published fare for {$cabin->label()} {$type->label()}. Ask a manager to set prices (card M7), then try again.",
            );
        }

        return $cents;
    }

    /**
     * @return array{label: string, quantity: int, unit_cents: int, amount_cents: int}
     */
    private function feeLine(FeeCode $code, int $quantity): array
    {
        $cents = Fee::query()->where('code', $code)->value('amount_cents');

        if ($cents === null) {
            throw new FareNotFound("No fee for {$code->label()}.");
        }

        return $this->line($code->label(), $quantity, $cents);
    }

    /**
     * @return array{label: string, quantity: int, unit_cents: int, amount_cents: int}
     */
    private function line(string $label, int $quantity, int $unitCents): array
    {
        return [
            'label' => $label,
            'quantity' => $quantity,
            'unit_cents' => $unitCents,
            'amount_cents' => $quantity * $unitCents,
        ];
    }
}
