<?php

namespace App\Services;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\SegmentDirection;
use App\Exceptions\InvalidJourney;
use App\Models\Airport;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\ReservationSegment;
use App\Models\User;
use App\Support\FlightAction;
use App\Support\FlightStatusGate;
use App\Support\Journey;
use App\Support\Limits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The flights of a reservation (B13b). The only place for the journey rules (SR-65):
 * flights connect, the connection is long enough, the return leaves after the outbound lands.
 * Also answers "which direction is this passenger checking in" and "where do the bags go".
 * Legs (B14, CR-16): segments inside one leg connect (MCT…24h); a gap over 24h starts a new leg.
 */
class JourneyService
{
    private const MAX_FLIGHTS_PER_DIRECTION = 3;

    /**
     * @throws InvalidJourney with a message for staff
     */
    public function validate(Journey $journey): void
    {
        if ($journey->cityLegs !== null) {
            $this->validateCityLegs($journey->cityLegs);

            return;
        }

        if ($journey->outbound === []) {
            throw new InvalidJourney('A journey needs at least one flight.');
        }

        $seen = [];
        foreach ($journey->legs() as [$flight]) {
            if (isset($seen[$flight->id])) {
                throw new InvalidJourney("{$flight->flight_no} is in the journey twice.");
            }
            $seen[$flight->id] = true;

            if (! FlightStatusGate::allows($flight, FlightAction::Book)) {
                throw new InvalidJourney("{$flight->flight_no} is {$flight->status->label()}: it cannot be booked.");
            }
        }

        $allLegs = [];
        foreach (SegmentDirection::cases() as $direction) {
            $flights = $journey->flights($direction);
            if ($flights === []) {
                continue;
            }

            if (count($flights) > self::MAX_FLIGHTS_PER_DIRECTION) {
                throw new InvalidJourney('At most '.self::MAX_FLIGHTS_PER_DIRECTION.' flights in each direction.');
            }

            $directionLegs = $this->partitionIntoLegs($flights);
            foreach ($directionLegs as $legFlights) {
                for ($i = 1; $i < count($legFlights); $i++) {
                    $this->ensureConnects($legFlights[$i - 1], $legFlights[$i]);
                }
            }
            $allLegs = array_merge($allLegs, $directionLegs);
        }

        // Round-trip shape/messages first (SR-65), then cross-leg order and the max-legs cap.
        if ($journey->return !== []) {
            $this->ensureRoundTrip($journey->outbound, $journey->return);
        }

        $this->ensureLegOrder($allLegs);
        $this->ensureLegCount($allLegs);
    }

    /**
     * Adds the journey's flights after the reservation's first flight (created with the reservation),
     * and a coupon on each new flight for every passenger already booked.
     *
     * @throws InvalidJourney
     */
    public function addSegments(Reservation $reservation, Journey $journey): void
    {
        $this->validate($journey);

        $first = $journey->cityLegs !== null
            ? ($journey->cityLegs[0][0] ?? null)
            : ($journey->outbound[0] ?? null);

        if ($first === null || $first->id !== $reservation->flight_id) {
            throw new InvalidJourney("The journey must start with {$reservation->flight->flight_no}, the reservation's flight.");
        }

        DB::transaction(function () use ($reservation, $journey) {
            $sequence = (int) $reservation->segments()->max('sequence');
            $passengers = $reservation->passengers()->get();
            $legNumbers = $this->legNumbersFor($journey);

            // First segment already exists (leg 1); update its leg if needed, then add the rest.
            $firstSegment = $reservation->segments()->where('sequence', 1)->first();
            if ($firstSegment !== null) {
                $firstSegment->forceFill(['leg' => $legNumbers[0] ?? 1])->save();
            }

            $index = 0;
            foreach (array_slice($journey->legs(), 1) as [$flight, $direction]) {
                $index++;
                $segment = $reservation->segments()->create([
                    'flight_id' => $flight->id,
                    'sequence' => ++$sequence,
                    'direction' => $direction,
                    'leg' => $legNumbers[$index] ?? 1,
                ]);

                foreach ($passengers as $passenger) {
                    $passenger->coupons()->create(['reservation_segment_id' => $segment->id, 'flight_id' => $flight->id]);
                }
            }
        });

        $reservation->unsetRelation('segments');
    }

    /**
     * The direction an agent checks in: the one whose first flight departs from the agent's station
     * (a CDG agent checks in the return of a BEY→CDG→BEY trip). Otherwise the passenger's current
     * coupon's direction, which is Outbound unless a page chose otherwise.
     */
    public function directionFor(Passenger $passenger, ?User $agent = null): SegmentDirection
    {
        $segments = $this->segments($passenger->reservation);

        if ($agent?->airport_code !== null) {
            foreach (SegmentDirection::cases() as $direction) {
                $first = $segments->firstWhere('direction', $direction);
                if ($first !== null && $first->flight->origin === $agent->airport_code) {
                    return $direction;
                }
            }
        }

        return $this->directionOf($passenger);
    }

    /**
     * The first flight of the direction the agent works (directionFor): the flight a check-in or gate
     * page is about when the URL names only the passenger.
     */
    public function firstFlightFor(Passenger $passenger, ?User $agent = null): Flight
    {
        return $this->flightsOf($passenger->reservation, $this->directionFor($passenger, $agent))->first()
            ?? $passenger->reservation->flight;
    }

    /**
     * The direction of the passenger's current coupon (Outbound by default).
     */
    public function directionOf(Passenger $passenger): SegmentDirection
    {
        $segmentId = $passenger->coupon?->reservation_segment_id;

        return $this->segments($passenger->reservation)->firstWhere('id', $segmentId)?->direction
            ?? SegmentDirection::Outbound;
    }

    /**
     * The passenger's coupons in one direction, in journey order, with their flights.
     *
     * @return Collection<int, Coupon>
     */
    public function couponsOf(Passenger $passenger, SegmentDirection $direction): Collection
    {
        return Coupon::query()
            ->select('coupons.*')
            ->join('reservation_segments', 'reservation_segments.id', '=', 'coupons.reservation_segment_id')
            ->where('coupons.passenger_id', $passenger->id)
            ->where('reservation_segments.direction', $direction)
            ->orderBy('reservation_segments.sequence')
            ->with('flight')
            ->get();
    }

    /**
     * The passenger's status for a direction: the status of its first coupon (check-in moves them together).
     */
    public function statusOf(Passenger $passenger, SegmentDirection $direction): PassengerStatus
    {
        return $this->couponsOf($passenger, $direction)->first()?->status ?? $passenger->status;
    }

    /**
     * The flights of one direction, in order.
     *
     * @return Collection<int, Flight>
     */
    public function flightsOf(Reservation $reservation, SegmentDirection $direction): Collection
    {
        return (new Collection($this->segments($reservation)->where('direction', $direction)->pluck('flight')->all()))->values();
    }

    /**
     * Where the bags of a direction go: the destination of its last flight (SR-67).
     */
    public function finalDestination(Reservation $reservation, SegmentDirection $direction): string
    {
        return $this->flightsOf($reservation, $direction)->last()?->destination
            ?? $reservation->flight->destination;
    }

    /**
     * Does any direction of the reservation have more than one flight? Group check-in and cabin
     * upgrades are not built for connections yet (B13b).
     */
    public function hasConnection(Reservation $reservation): bool
    {
        return $this->segments($reservation)->countBy(fn ($segment) => $segment->direction->value)->max() > 1;
    }

    /**
     * More than one flight in this direction?
     */
    public function isConnection(Reservation $reservation, SegmentDirection $direction): bool
    {
        return $this->flightsOf($reservation, $direction)->count() > 1;
    }

    /**
     * The leg number of a segment (B14, CR-16).
     */
    public function legOf(ReservationSegment $segment): int
    {
        return (int) $segment->leg;
    }

    /**
     * Segments grouped by leg number, in leg order (B14, CR-16).
     *
     * @return Collection<int, Collection<int, ReservationSegment>>
     */
    public function legs(Reservation $reservation): Collection
    {
        return $this->segments($reservation)
            ->groupBy(fn (ReservationSegment $segment) => $segment->leg)
            ->sortKeys()
            ->values();
    }

    /**
     * Distinct leg numbers on the reservation, ascending (CR-16).
     *
     * @return list<int>
     */
    public function legNumbers(Reservation $reservation): array
    {
        return $this->segments($reservation)
            ->pluck('leg')
            ->map(fn ($leg) => (int) $leg)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * More than one leg on the booking (multi-city or overnight split, or outbound+return).
     */
    public function hasMultipleLegs(Reservation $reservation): bool
    {
        return count($this->legNumbers($reservation)) > 1;
    }

    /**
     * Segments of one leg, in sequence order.
     *
     * @return Collection<int, ReservationSegment>
     */
    public function segmentsOfLeg(Reservation $reservation, int $leg): Collection
    {
        return $this->segments($reservation)
            ->where('leg', $leg)
            ->values();
    }

    /**
     * Flights of one leg, in order (C23 / CR-16).
     *
     * @return Collection<int, Flight>
     */
    public function flightsOfLeg(Reservation $reservation, int $leg): Collection
    {
        return new Collection(
            $this->segmentsOfLeg($reservation, $leg)->pluck('flight')->all()
        );
    }

    /**
     * Coupons of one leg, in journey order (C23 / CR-16).
     *
     * @return Collection<int, Coupon>
     */
    public function couponsOfLeg(Passenger $passenger, int $leg): Collection
    {
        return Coupon::query()
            ->select('coupons.*')
            ->join('reservation_segments', 'reservation_segments.id', '=', 'coupons.reservation_segment_id')
            ->where('coupons.passenger_id', $passenger->id)
            ->where('reservation_segments.leg', $leg)
            ->orderBy('reservation_segments.sequence')
            ->with('flight')
            ->get();
    }

    /**
     * Status of the first coupon of the leg (check-in moves the leg together).
     */
    public function statusOfLeg(Passenger $passenger, int $leg): PassengerStatus
    {
        return $this->couponsOfLeg($passenger, $leg)->first()?->status ?? $passenger->status;
    }

    /**
     * Bags of this leg go to the last flight's destination (CR-16 / SR-67 per leg).
     */
    public function finalDestinationOfLeg(Reservation $reservation, int $leg): string
    {
        return $this->flightsOfLeg($reservation, $leg)->last()?->destination
            ?? $reservation->flight->destination;
    }

    /**
     * More than one flight inside this leg?
     */
    public function isConnectionLeg(Reservation $reservation, int $leg): bool
    {
        return $this->flightsOfLeg($reservation, $leg)->count() > 1;
    }

    /**
     * Which leg the agent is checking in (C23 / CR-16): prefer a leg whose first flight
     * departs from their station and whose check-in window is open; else that station's
     * earliest unfinished leg; else the first leg of the direction they would have used.
     */
    public function legFor(Passenger $passenger, ?User $agent = null, ?SegmentDirection $direction = null): int
    {
        $numbers = $this->legNumbers($passenger->reservation);

        if ($numbers === []) {
            return 1;
        }

        if ($agent?->airport_code !== null) {
            $atStation = [];
            foreach ($numbers as $leg) {
                $first = $this->flightsOfLeg($passenger->reservation, $leg)->first();
                if ($first !== null && $first->origin === $agent->airport_code) {
                    $atStation[] = $leg;
                }
            }

            foreach ($atStation as $leg) {
                $first = $this->flightsOfLeg($passenger->reservation, $leg)->first();
                if ($first?->isCheckinWindowOpen()) {
                    return $leg;
                }
            }

            foreach ($atStation as $leg) {
                $status = $this->statusOfLeg($passenger, $leg);
                if (in_array($status, [PassengerStatus::Booked, PassengerStatus::CheckedIn], true)) {
                    return $leg;
                }
            }

            if ($atStation !== []) {
                return $atStation[0];
            }
        }

        if ($direction !== null) {
            $segment = $this->segments($passenger->reservation)->firstWhere('direction', $direction);

            return $segment !== null ? (int) $segment->leg : $numbers[0];
        }

        return $numbers[0];
    }

    /**
     * Earlier legs must be flown (Boarded), no-show, or cancelled before a later leg checks in (C23).
     * Round-trip return (a later Return leg) does not wait on the outbound: SR-66 checks the return
     * in at its own origin independently. Multi-city / overnight Outbound legs still wait.
     */
    public function earlierLegsAreComplete(Passenger $passenger, int $leg): bool
    {
        $activeDirection = $this->segmentsOfLeg($passenger->reservation, $leg)->first()?->direction;

        foreach ($this->legNumbers($passenger->reservation) as $earlier) {
            if ($earlier >= $leg) {
                break;
            }

            $earlierDirection = $this->segmentsOfLeg($passenger->reservation, $earlier)->first()?->direction;
            if ($activeDirection === SegmentDirection::Return && $earlierDirection === SegmentDirection::Outbound) {
                continue;
            }

            foreach ($this->couponsOfLeg($passenger, $earlier) as $coupon) {
                if (in_array($coupon->status, [PassengerStatus::Booked, PassengerStatus::CheckedIn], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return Collection<int, ReservationSegment>
     */
    private function segments(Reservation $reservation): Collection
    {
        return $reservation->relationLoaded('segments') && $reservation->segments->every(fn ($segment) => $segment->relationLoaded('flight'))
            ? $reservation->segments
            : $reservation->load('segments.flight')->segments;
    }

    /**
     * @param  list<list<Flight>>  $cityLegs
     */
    private function validateCityLegs(array $cityLegs): void
    {
        if ($cityLegs === []) {
            throw new InvalidJourney('A journey needs at least one flight.');
        }

        $seen = [];
        foreach ($cityLegs as $legFlights) {
            if ($legFlights === []) {
                throw new InvalidJourney('A journey needs at least one flight.');
            }
            if (count($legFlights) > self::MAX_FLIGHTS_PER_DIRECTION) {
                throw new InvalidJourney('At most '.self::MAX_FLIGHTS_PER_DIRECTION.' flights in each leg.');
            }
            foreach ($legFlights as $flight) {
                if (isset($seen[$flight->id])) {
                    throw new InvalidJourney("{$flight->flight_no} is in the journey twice.");
                }
                $seen[$flight->id] = true;
                if (in_array($flight->status, [FlightStatus::Cancelled, FlightStatus::Closed, FlightStatus::Departed], true)) {
                    throw new InvalidJourney("{$flight->flight_no} is {$flight->status->label()}: it cannot be booked.");
                }
            }
            for ($i = 1; $i < count($legFlights); $i++) {
                $this->ensureConnects($legFlights[$i - 1], $legFlights[$i]);
            }
        }

        $this->ensureLegOrder($cityLegs);
        $this->ensureLegCount($cityLegs);
    }

    /**
     * Split a direction's flights into legs: only a gap over max_connection_hours at the same
     * connecting city starts a new leg. Non-connecting cities stay in the same group so
     * ensureConnects can refuse them (SR-65).
     *
     * @param  list<Flight>  $flights
     * @return list<list<Flight>>
     */
    private function partitionIntoLegs(array $flights): array
    {
        if ($flights === []) {
            return [];
        }

        $maxMinutes = Limits::maxConnectionHours() * 60;
        $legs = [[$flights[0]]];

        for ($i = 1; $i < count($flights); $i++) {
            $previous = $flights[$i - 1];
            $next = $flights[$i];
            $gap = $this->gapMinutes($previous, $next);

            if ($next->origin === $previous->destination && $gap !== null && $gap > $maxMinutes) {
                $legs[] = [$next];
            } else {
                $legs[array_key_last($legs)][] = $next;
            }
        }

        return $legs;
    }

    /**
     * Leg number for each flight in journey order (same length as Journey::legs()).
     *
     * @return list<int>
     */
    private function legNumbersFor(Journey $journey): array
    {
        if ($journey->cityLegs !== null) {
            $numbers = [];
            $leg = 1;
            foreach ($journey->cityLegs as $flights) {
                foreach ($flights as $_) {
                    $numbers[] = $leg;
                }
                $leg++;
            }

            return $numbers;
        }

        $numbers = [];
        $leg = 0;
        foreach (SegmentDirection::cases() as $direction) {
            $flights = $journey->flights($direction);
            if ($flights === []) {
                continue;
            }
            foreach ($this->partitionIntoLegs($flights) as $legFlights) {
                $leg++;
                foreach ($legFlights as $_) {
                    $numbers[] = $leg;
                }
            }
        }

        return $numbers;
    }

    /**
     * @param  list<list<Flight>>  $legs
     */
    private function ensureLegOrder(array $legs): void
    {
        for ($i = 1; $i < count($legs); $i++) {
            $previous = end($legs[$i - 1]);
            $next = $legs[$i][0];

            if ($previous->arrives_at === null) {
                throw new InvalidJourney("Set the arrival time of {$previous->flight_no} first.");
            }
            if ($next->departure_at->lt($previous->arrives_at)) {
                throw new InvalidJourney("Leg {$i} starts before the previous leg ends ({$previous->flight_no} lands after {$next->flight_no} departs).");
            }
        }
    }

    /**
     * @param  list<list<Flight>>  $legs
     */
    private function ensureLegCount(array $legs): void
    {
        $max = Limits::maxLegs();
        if (count($legs) > $max) {
            throw new InvalidJourney('At most '.$max.' legs in a journey.');
        }
    }

    private function ensureConnects(Flight $previous, Flight $next): void
    {
        if ($next->origin !== $previous->destination) {
            throw new InvalidJourney("{$next->flight_no} departs from {$next->origin}, but {$previous->flight_no} lands at {$previous->destination}.");
        }

        $this->ensureEnoughTime($previous, $next);
    }

    /**
     * @param  list<Flight>  $outbound
     * @param  list<Flight>  $return
     */
    private function ensureRoundTrip(array $outbound, array $return): void
    {
        $lastOut = end($outbound);
        $firstBack = $return[0];
        $lastBack = end($return);

        if ($firstBack->origin !== $lastOut->destination) {
            throw new InvalidJourney("The return must start at {$lastOut->destination}, where the outbound ends.");
        }
        if ($lastBack->destination !== $outbound[0]->origin) {
            throw new InvalidJourney("The return must end at {$outbound[0]->origin}, where the outbound starts.");
        }
        if ($lastOut->arrives_at !== null && $firstBack->departure_at->lte($lastOut->arrives_at)) {
            throw new InvalidJourney("The return {$firstBack->flight_no} departs before the outbound lands at {$lastOut->destination}.");
        }

        // Between outbound and return the gap may exceed 24h (a new leg); only require order + MCT when short.
        $gap = $this->gapMinutes($lastOut, $firstBack);
        $maxMinutes = Limits::maxConnectionHours() * 60;
        if ($gap !== null && $gap <= $maxMinutes) {
            $this->ensureEnoughTime($lastOut, $firstBack);
        }
    }

    /**
     * The next flight leaves at least the connecting airport's minimum connection time after landing,
     * and at most max_connection_hours (longer gaps are a new leg, not a connection).
     */
    private function ensureEnoughTime(Flight $previous, Flight $next): void
    {
        if ($previous->arrives_at === null) {
            throw new InvalidJourney("Set the arrival time of {$previous->flight_no} first.");
        }

        $gap = (int) $previous->arrives_at->diffInMinutes($next->departure_at, false);
        $minimum = (int) (Airport::find($previous->destination)?->min_connection_minutes ?? 60);

        if ($gap < $minimum) {
            throw new InvalidJourney("Connection at {$previous->destination} is {$gap} min: at least {$minimum} min needed.");
        }
    }

    private function gapMinutes(Flight $previous, Flight $next): ?int
    {
        if ($previous->arrives_at === null || $next->departure_at === null) {
            return null;
        }

        return (int) $previous->arrives_at->diffInMinutes($next->departure_at, false);
    }
}
