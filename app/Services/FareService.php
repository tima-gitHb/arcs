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
     * Per-passenger, per-flight fare cents for a booking (no extras). Trip fares that cover
     * several flights are split in proportion to each flight's route fare (Sprint 4 Feature 2).
     *
     * @return list<array{passenger_id: int, flight_id: int, leg: int, amount_cents: int}>
     */
    public function passengerFlightBreakdown(Reservation $reservation): array
    {
        $reservation->loadMissing(['segments.flight', 'passengers']);
        $items = [];

        $legs = app(JourneyService::class)->legs($reservation);
        $hasReturn = $reservation->segments->contains(
            fn (ReservationSegment $segment) => $segment->direction === SegmentDirection::Return,
        );

        if ($legs->count() > 1 && ! $hasReturn) {
            foreach ($legs as $index => $segments) {
                $legNo = (int) ($segments->first()->leg ?? ($index + 1));
                $origin = $segments->first()->flight->origin;
                $destination = $segments->last()->flight->destination;
                $flights = $segments->pluck('flight')->values();

                foreach ($reservation->passengers as $passenger) {
                    $cabin = $passenger->cabin_class ?? CabinClass::Economy;
                    $trip = TripFare::query()
                        ->forTrip($origin, $destination)
                        ->where('cabin_class', $cabin)
                        ->where('passenger_type', $passenger->type)
                        ->first();

                    if ($trip !== null) {
                        $allocated = $this->splitProportionally(
                            $trip->one_way_cents,
                            $flights->mapWithKeys(fn ($flight) => [
                                $flight->id => $this->fareCents($cabin, $passenger->type, $flight->origin, $flight->destination),
                            ])->all(),
                        );
                        foreach ($flights as $flight) {
                            $items[] = [
                                'passenger_id' => $passenger->id,
                                'flight_id' => $flight->id,
                                'leg' => $legNo,
                                'amount_cents' => $allocated[$flight->id] ?? 0,
                            ];
                        }

                        continue;
                    }

                    foreach ($segments as $segment) {
                        $flight = $segment->flight;
                        $items[] = [
                            'passenger_id' => $passenger->id,
                            'flight_id' => $flight->id,
                            'leg' => $legNo,
                            'amount_cents' => $this->fareCents($cabin, $passenger->type, $flight->origin, $flight->destination),
                        ];
                    }
                }
            }

            return $items;
        }

        $outbound = $reservation->segments->where('direction', SegmentDirection::Outbound)->values();
        if ($outbound->isEmpty()) {
            $outbound = $reservation->segments->values();
        }
        $returnSegs = $reservation->segments->where('direction', SegmentDirection::Return)->values();
        $origin = $outbound->first()->flight->origin;
        $destination = $outbound->last()->flight->destination;

        foreach ($reservation->passengers as $passenger) {
            $cabin = $passenger->cabin_class ?? CabinClass::Economy;
            $trip = TripFare::query()
                ->forTrip($origin, $destination)
                ->where('cabin_class', $cabin)
                ->where('passenger_type', $passenger->type)
                ->first();

            $outboundFlights = $outbound->pluck('flight')->values();
            $returnFlights = $returnSegs->pluck('flight')->values();

            if ($trip !== null) {
                $outCents = $trip->one_way_cents;
                $retCents = ($hasReturn && $trip->round_trip_cents !== null)
                    ? max(0, $trip->round_trip_cents - $trip->one_way_cents)
                    : ($hasReturn ? $trip->one_way_cents : 0);

                if ($hasReturn && $trip->round_trip_cents !== null) {
                    // Split the whole round-trip across all flights by route weights.
                    $all = $outboundFlights->concat($returnFlights)->values();
                    $allocated = $this->splitProportionally(
                        $trip->round_trip_cents,
                        $all->mapWithKeys(fn ($flight) => [
                            $flight->id => $this->fareCents($cabin, $passenger->type, $flight->origin, $flight->destination),
                        ])->all(),
                    );
                    foreach ($outbound as $segment) {
                        $items[] = [
                            'passenger_id' => $passenger->id,
                            'flight_id' => $segment->flight_id,
                            'leg' => (int) $segment->leg,
                            'amount_cents' => $allocated[$segment->flight_id] ?? 0,
                        ];
                    }
                    foreach ($returnSegs as $segment) {
                        $items[] = [
                            'passenger_id' => $passenger->id,
                            'flight_id' => $segment->flight_id,
                            'leg' => (int) $segment->leg,
                            'amount_cents' => $allocated[$segment->flight_id] ?? 0,
                        ];
                    }

                    continue;
                }

                $outAlloc = $this->splitProportionally(
                    $outCents,
                    $outboundFlights->mapWithKeys(fn ($flight) => [
                        $flight->id => $this->fareCents($cabin, $passenger->type, $flight->origin, $flight->destination),
                    ])->all(),
                );
                foreach ($outbound as $segment) {
                    $items[] = [
                        'passenger_id' => $passenger->id,
                        'flight_id' => $segment->flight_id,
                        'leg' => (int) $segment->leg,
                        'amount_cents' => $outAlloc[$segment->flight_id] ?? 0,
                    ];
                }
                if ($hasReturn) {
                    $retAlloc = $this->splitProportionally(
                        $retCents,
                        $returnFlights->mapWithKeys(fn ($flight) => [
                            $flight->id => $this->fareCents($cabin, $passenger->type, $flight->origin, $flight->destination),
                        ])->all(),
                    );
                    foreach ($returnSegs as $segment) {
                        $items[] = [
                            'passenger_id' => $passenger->id,
                            'flight_id' => $segment->flight_id,
                            'leg' => (int) $segment->leg,
                            'amount_cents' => $retAlloc[$segment->flight_id] ?? 0,
                        ];
                    }
                }

                continue;
            }

            foreach ($reservation->segments as $segment) {
                $flight = $segment->flight;
                $items[] = [
                    'passenger_id' => $passenger->id,
                    'flight_id' => $flight->id,
                    'leg' => (int) $segment->leg,
                    'amount_cents' => $this->fareCents($cabin, $passenger->type, $flight->origin, $flight->destination),
                ];
            }
        }

        return $items;
    }

    /**
     * Split $total across keys by weight; cents sum exactly to $total (largest remainder).
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    private function splitProportionally(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            $each = intdiv($total, count($weights));
            $result = array_fill_keys(array_keys($weights), $each);
            $result[array_key_first($result)] += $total - array_sum($result);

            return $result;
        }

        $raw = [];
        $floors = [];
        foreach ($weights as $key => $weight) {
            $exact = $total * $weight / $sum;
            $floors[$key] = (int) floor($exact);
            $raw[$key] = $exact - $floors[$key];
        }
        $left = $total - array_sum($floors);
        arsort($raw);
        foreach (array_keys($raw) as $key) {
            if ($left <= 0) {
                break;
            }
            $floors[$key]++;
            $left--;
        }

        return $floors;
    }

    /**
     * Fare for one leg's flights for the given passengers (same rules as booking: trip fare
     * origin→final dest when set, else per-route sum). Used by ticket exchange (Sprint 4 Feature 3).
     *
     * @param  list<Passenger>|Collection<int, Passenger>  $passengers
     * @param  list<Flight>|Collection<int, \App\Models\Flight>  $flights
     */
    public function quoteLegFlights($passengers, $flights): Quote
    {
        $flightList = collect($flights)->values();
        if ($flightList->isEmpty()) {
            return new Quote([], 0, self::CURRENCY);
        }

        $passengerRows = collect($passengers)->map(fn (Passenger $passenger) => [
            'cabin_class' => $passenger->cabin_class ?? CabinClass::Economy,
            'type' => $passenger->type,
        ])->all();

        $origin = $flightList->first()->origin;
        $destination = $flightList->last()->destination;

        $trip = $this->tripFareLines($passengerRows, $origin, $destination, hasReturn: false);
        if ($trip !== null) {
            return new Quote($trip, array_sum(array_column($trip, 'amount_cents')), self::CURRENCY);
        }

        $lines = [];
        foreach ($flightList as $flight) {
            foreach ($this->quote($passengerRows, origin: $flight->origin, destination: $flight->destination)->lines as $line) {
                $lines[] = ['label' => "{$flight->origin}→{$flight->destination} {$flight->flight_no}: {$line['label']}"] + $line;
            }
        }

        return new Quote($lines, array_sum(array_column($lines, 'amount_cents')), self::CURRENCY);
    }

    /**
     * Cancellation fee: amount × passengers × legs (Sprint 4 Feature 1).
     */
    public function cancellationFee(int $passengers, int $legs): Quote
    {
        $quantity = max(0, $passengers) * max(0, $legs);

        return new Quote(
            $quantity > 0 ? [$this->feeLine(FeeCode::Cancellation, $quantity)] : [],
            $quantity > 0 ? $this->feeLine(FeeCode::Cancellation, $quantity)['amount_cents'] : 0,
            self::CURRENCY,
        );
    }

    /**
     * Change fee: amount × passengers × legs (Sprint 4 Feature 1).
     */
    public function changeFee(int $passengers, int $legs): Quote
    {
        $quantity = max(0, $passengers) * max(0, $legs);

        return new Quote(
            $quantity > 0 ? [$this->feeLine(FeeCode::Change, $quantity)] : [],
            $quantity > 0 ? $this->feeLine(FeeCode::Change, $quantity)['amount_cents'] : 0,
            self::CURRENCY,
        );
    }

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
