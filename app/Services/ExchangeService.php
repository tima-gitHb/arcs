<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\ExchangeNotAllowed;
use App\Exceptions\InvalidJourney;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\SeatTaken;
use App\Mail\ETicketMail;
use App\Models\AuditEntry;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSegment;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\Payments\CardDetails;
use App\Support\FlightAction;
use App\Support\FlightStatusGate;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Ticket exchange: change one leg's flights for selected passengers (Sprint 4 Feature 3).
 * Reuses RefundService breakdown, FareService pricing, JourneyService validation.
 */
class ExchangeService
{
    public function __construct(
        private FareService $fares,
        private RefundService $refunds,
        private JourneyService $journeys,
        private StatusService $statuses,
        private PaymentService $payments,
        private SeatService $seats,
    ) {}

    /**
     * Flights that can replace this leg on a given local date (same origin→destination, bookable).
     *
     * @param  list<int>  $passengerIds  When set, only those passengers' current flights are excluded
     *                                   (so another passenger can join a flight already taken by a prior exchange).
     * @return Collection<int, Flight>
     */
    public function candidateFlights(Reservation $reservation, int $leg, Carbon $localDate, array $passengerIds = []): Collection
    {
        $routeFlights = $this->routeFlightsForCandidates($reservation, $leg, $passengerIds);
        if ($routeFlights->isEmpty()) {
            return collect();
        }

        $origin = $routeFlights->first()->origin;
        $destination = $routeFlights->last()->destination;
        $exclude = $routeFlights->pluck('id')->all();
        $bookable = array_map(fn ($s) => $s->value, FlightStatusGate::bookableStatuses());

        $day = $localDate->toDateString();

        return Flight::query()
            ->where('origin', $origin)
            ->where('destination', $destination)
            ->whereIn('status', $bookable)
            ->whereNotIn('id', $exclude)
            ->orderBy('departure_at')
            ->get()
            ->filter(fn (Flight $flight) => $flight->departureLocal()->toDateString() === $day)
            ->values();
    }

    /**
     * @param  list<int>  $passengerIds
     * @param  list<int>  $newFlightIds
     * @return array<string, mixed>
     */
    public function preview(Reservation $reservation, int $leg, array $passengerIds, array $newFlightIds): array
    {
        $reservation->loadMissing([
            'passengers.coupons.flight',
            'passengers.coupons.segment',
            'segments.flight',
            'payments',
        ]);

        $passengers = $this->resolvePassengers($reservation, $passengerIds);
        $oldCoupons = $this->oldCoupons($passengers, $leg);
        $this->assertOldEligible($oldCoupons);

        $newFlights = $this->resolveNewFlights($newFlightIds);
        try {
            $this->journeys->assertLegReplacement($reservation, $leg, $newFlights->all());
        } catch (InvalidJourney $exception) {
            throw new ExchangeNotAllowed($exception->getMessage(), 0, $exception);
        }

        $this->assertCabinCapacity($passengers, $newFlights);

        $paid = $this->refunds->paidForCoupons($reservation, $oldCoupons);
        $newQuote = $this->fares->quoteLegFlights($passengers, $newFlights);
        $paidCents = $paid['total'];
        $newCents = $newQuote->totalCents;
        $difference = $newCents - $paidCents;

        $feeUnits = 0;
        foreach ($passengers as $passenger) {
            $passengerPaid = 0;
            foreach ($oldCoupons->where('passenger_id', $passenger->id) as $coupon) {
                $passengerPaid += $paid['by_coupon'][$coupon->id] ?? 0;
            }
            if ($passenger->type === PassengerType::Infant && $passengerPaid === 0 && $this->passengerNewFare($passenger, $newFlights) === 0) {
                continue;
            }
            $feeUnits++;
        }
        $feeCents = $feeUnits > 0
            ? $this->fares->changeFee(1, 1)->totalCents * $feeUnits
            : 0;

        $fareUplift = max(0, $difference);
        $chargeCents = $fareUplift + $feeCents;
        $differenceNotRefunded = $difference < 0;

        $oldFlights = $oldCoupons
            ->sortBy(fn (Coupon $c) => $c->segment?->sequence ?? 0)
            ->pluck('flight')
            ->unique('id')
            ->values();

        return [
            'leg' => $leg,
            'passengers' => $passengers->map(fn (Passenger $p) => [
                'id' => $p->id,
                'name' => trim($p->first_name.' '.$p->last_name),
                'type' => $p->type->value,
            ])->all(),
            'old_flights' => $oldFlights->map(fn (Flight $f) => $this->flightSummary($f))->all(),
            'new_flights' => $newFlights->map(fn (Flight $f) => $this->flightSummary($f))->all(),
            'old_coupon_ids' => $oldCoupons->pluck('id')->all(),
            'paid_cents' => $paidCents,
            'new_fare_cents' => $newCents,
            'difference_cents' => $difference,
            'fee_cents' => $feeCents,
            'charge_cents' => $chargeCents,
            'difference_not_refunded' => $differenceNotRefunded,
            'change_note' => $this->changeNote($oldFlights, $newFlights),
        ];
    }

    /**
     * @param  list<int>  $passengerIds
     * @param  list<int>  $newFlightIds
     * @param  array<int, array<int, int>>  $seats  passenger_id => [flight_id => seat_id]
     * @return array{payment: Payment|null, preview: array<string, mixed>}
     */
    public function confirm(
        Reservation $reservation,
        User $by,
        int $leg,
        array $passengerIds,
        array $newFlightIds,
        ?CardDetails $card,
        array $seats = [],
    ): array {
        return DB::transaction(function () use ($reservation, $by, $leg, $passengerIds, $newFlightIds, $card, $seats) {
            $preview = $this->preview($reservation, $leg, $passengerIds, $newFlightIds);
            $passengers = $this->resolvePassengers($reservation, $passengerIds);
            $oldCoupons = Coupon::query()->whereIn('id', $preview['old_coupon_ids'])->with(['passenger', 'flight', 'segment'])->get();
            $newFlights = $this->resolveNewFlights($newFlightIds);
            $direction = $this->journeys->segmentsOfLeg($reservation, $leg)->first()?->direction;

            foreach ($passengers as $passenger) {
                $theirs = $oldCoupons->where('passenger_id', $passenger->id);
                $this->statuses->exchangeCoupons($passenger, $by, $theirs);
            }

            $sequence = (int) $reservation->segments()->max('sequence');
            $createdCoupons = collect();

            foreach ($newFlights as $flight) {
                $segment = ReservationSegment::query()
                    ->where('reservation_id', $reservation->id)
                    ->where('flight_id', $flight->id)
                    ->where('leg', $leg)
                    ->first();

                if ($segment === null) {
                    $segment = $reservation->segments()->create([
                        'flight_id' => $flight->id,
                        'sequence' => ++$sequence,
                        'direction' => $direction,
                        'leg' => $leg,
                    ]);
                }

                foreach ($passengers as $passenger) {
                    $coupon = $passenger->coupons()->create([
                        'reservation_segment_id' => $segment->id,
                        'flight_id' => $flight->id,
                    ]);
                    $createdCoupons->push($coupon);

                    if ($passenger->type === PassengerType::Infant) {
                        continue;
                    }

                    $seatId = $seats[$passenger->id][$flight->id]
                        ?? $seats[(string) $passenger->id][$flight->id]
                        ?? $seats[$passenger->id][(string) $flight->id]
                        ?? null;

                    if ($seatId) {
                        $this->assignSeat($passenger, $flight, (int) $seatId);
                    } else {
                        $this->autoAssignSeat($passenger, $flight);
                    }
                }
            }

            $payment = null;
            if ($preview['charge_cents'] > 0) {
                if ($card === null) {
                    throw new ExchangeNotAllowed('A card is required to pay for this exchange.');
                }
                $payment = $this->payments->charge(
                    $card,
                    $preview['charge_cents'],
                    PaymentKind::Exchange,
                    $by,
                    reservation: $reservation,
                    breakdown: [
                        'items' => [[
                            'type' => 'exchange',
                            'leg' => $leg,
                            'difference_cents' => max(0, $preview['difference_cents']),
                            'fee_cents' => $preview['fee_cents'],
                            'amount_cents' => $preview['charge_cents'],
                        ]],
                    ],
                );
                if ($payment->status !== PaymentStatus::Succeeded) {
                    throw new PaymentDeclined($payment->failureMessage ?? 'Card declined.');
                }
            }

            AuditEntry::create([
                'flight_id' => $newFlights->first()->id,
                'field' => 'exchange',
                'old_value' => mb_substr(collect($preview['old_flights'])->pluck('label')->implode(', '), 0, 255),
                'new_value' => mb_substr(
                    collect($preview['new_flights'])->pluck('label')->implode(', ')
                    .' · '.Money::format($preview['charge_cents']),
                    0,
                    255,
                ),
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            if ($reservation->contact_email) {
                $mailPayment = $payment ?? $reservation->farePayment();
                if ($mailPayment !== null) {
                    Mail::to($reservation->contact_email)->send(new ETicketMail(
                        $reservation->fresh(['passengers.coupons.flight', 'segments.flight']),
                        $mailPayment,
                        $preview['change_note'],
                    ));
                }
            }

            return ['payment' => $payment, 'preview' => $preview, 'new_coupons' => $createdCoupons];
        });
    }

    /**
     * @param  list<int>  $passengerIds
     * @return Collection<int, Passenger>
     */
    private function resolvePassengers(Reservation $reservation, array $passengerIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $passengerIds)));
        if ($ids === []) {
            throw new ExchangeNotAllowed('Select at least one passenger.');
        }

        $passengers = $reservation->passengers->whereIn('id', $ids)->values();
        if ($passengers->count() !== count($ids)) {
            throw new ExchangeNotAllowed('Passenger is not on this booking.');
        }

        return $passengers;
    }

    /**
     * @param  Collection<int, Passenger>  $passengers
     * @return Collection<int, Coupon>
     */
    private function oldCoupons(Collection $passengers, int $leg): Collection
    {
        $coupons = collect();
        foreach ($passengers as $passenger) {
            $legCoupons = $this->journeys->couponsOfLeg($passenger, $leg);
            if ($legCoupons->isEmpty()) {
                throw new ExchangeNotAllowed(
                    trim($passenger->first_name.' '.$passenger->last_name)." has no flights on leg {$leg}.",
                );
            }
            $coupons = $coupons->concat($legCoupons);
        }

        return $coupons->values();
    }

    /**
     * @param  Collection<int, Coupon>  $coupons
     */
    private function assertOldEligible(Collection $coupons): void
    {
        foreach ($coupons as $coupon) {
            $coupon->loadMissing(['passenger', 'flight']);
            $name = trim($coupon->passenger->first_name.' '.$coupon->passenger->last_name);

            if ($coupon->status !== PassengerStatus::Booked) {
                throw new ExchangeNotAllowed(
                    "{$name} is {$coupon->status->label()} on {$coupon->flight->flight_no} — cannot exchange.",
                );
            }

            if (! FlightStatusGate::allows($coupon->flight, FlightAction::Exchange)) {
                throw new ExchangeNotAllowed(
                    "{$coupon->flight->flight_no} is {$coupon->flight->status->label()} — cannot exchange.",
                );
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Flight>
     */
    private function resolveNewFlights(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new ExchangeNotAllowed('Choose the new flight(s).');
        }

        $flights = Flight::query()->whereIn('id', $ids)->get()->sortBy(fn (Flight $f) => array_search($f->id, $ids, true))->values();
        if ($flights->count() !== count($ids)) {
            throw new ExchangeNotAllowed('A selected flight was not found.');
        }

        return $flights;
    }

    /**
     * @param  Collection<int, Passenger>  $passengers
     * @param  Collection<int, Flight>  $flights
     */
    private function assertCabinCapacity(Collection $passengers, Collection $flights): void
    {
        foreach ($flights as $flight) {
            $needed = $passengers->filter(fn (Passenger $p) => $p->type !== PassengerType::Infant)
                ->groupBy(fn (Passenger $p) => ($p->cabin_class ?? CabinClass::Economy)->value);

            foreach ($needed as $cabin => $group) {
                $map = $this->seats->seatMap($flight);
                $free = $map->filter(fn (array $seat) => $seat['cabin'] === $cabin && ! $seat['taken'])->count();
                if ($free < $group->count()) {
                    throw new ExchangeNotAllowed(
                        "{$flight->flight_no} has no free {$cabin} seats for this exchange.",
                    );
                }
            }
        }
    }

    /**
     * @param  Collection<int, Flight>  $flights
     */
    private function passengerNewFare(Passenger $passenger, Collection $flights): int
    {
        return $this->fares->quoteLegFlights([$passenger], $flights)->totalCents;
    }

    /**
     * @return array{id: int, flight_no: string, origin: string, destination: string, label: string, departure: string}
     */
    private function flightSummary(Flight $flight): array
    {
        $when = $flight->departureLocal()->format('d M Y H:i');

        return [
            'id' => $flight->id,
            'flight_no' => $flight->flight_no,
            'origin' => $flight->origin,
            'destination' => $flight->destination,
            'departure' => $when,
            'label' => "{$flight->flight_no} {$flight->origin}→{$flight->destination} {$when}",
        ];
    }

    /**
     * @param  Collection<int, Flight>  $old
     * @param  Collection<int, Flight>  $new
     */
    private function changeNote(Collection $old, Collection $new): string
    {
        $from = $old->map(fn (Flight $f) => $f->flight_no.' '.$f->departureLocal()->format('j M'))->implode(', ');
        $to = $new->map(fn (Flight $f) => $f->flight_no.' '.$f->departureLocal()->format('j M'))->implode(', ');

        return 'Changed on '.now()->format('j M Y').": {$from} → {$to}";
    }

    private function assignSeat(Passenger $passenger, Flight $flight, int $seatId): void
    {
        $seat = Seat::query()->where('aircraft_id', $flight->aircraft_id)->whereKey($seatId)->first();
        if ($seat === null) {
            throw new ExchangeNotAllowed('Choose a seat on the new flight.');
        }

        $cabin = $passenger->cabin_class ?? CabinClass::Economy;
        if ($seat->cabin_class !== $cabin) {
            throw new ExchangeNotAllowed('Choose a seat in the booked cabin.');
        }

        try {
            SeatAssignment::create([
                'flight_id' => $flight->id,
                'seat_id' => $seat->id,
                'passenger_id' => $passenger->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new SeatTaken;
        }
    }

    private function autoAssignSeat(Passenger $passenger, Flight $flight): void
    {
        $cabin = ($passenger->cabin_class ?? CabinClass::Economy)->value;
        $map = $this->seats->seatMap($flight);
        $free = $map->first(fn (array $seat) => $seat['cabin'] === $cabin && ! $seat['taken']);
        if ($free === null) {
            throw new ExchangeNotAllowed("{$flight->flight_no} has no free {$cabin} seats for this exchange.");
        }
        $this->assignSeat($passenger, $flight, $free['id']);
    }

    /**
     * Flights that define the route (and exclusions) for the candidate search.
     *
     * @param  list<int>  $passengerIds
     * @return Collection<int, Flight>
     */
    private function routeFlightsForCandidates(Reservation $reservation, int $leg, array $passengerIds): Collection
    {
        if ($passengerIds !== []) {
            $passengers = $reservation->passengers->whereIn('id', array_map('intval', $passengerIds));
            $flights = collect();
            foreach ($passengers as $passenger) {
                foreach ($this->journeys->couponsOfLeg($passenger, $leg) as $coupon) {
                    $flights->push($coupon->flight);
                }
            }

            return $flights->unique('id')->values();
        }

        return $this->journeys->flightsOfLeg($reservation, $leg);
    }
}
