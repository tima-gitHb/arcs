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
use App\Support\Journey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The flights of a reservation (B13b). The only place for the journey rules (SR-65):
 * flights connect, the connection is long enough, the return leaves after the outbound lands.
 * Also answers "which direction is this passenger checking in" and "where do the bags go".
 */
class JourneyService
{
    private const MAX_FLIGHTS_PER_DIRECTION = 3;

    /**
     * @throws InvalidJourney with a message for staff
     */
    public function validate(Journey $journey): void
    {
        if ($journey->outbound === []) {
            throw new InvalidJourney('A journey needs at least one flight.');
        }

        $seen = [];
        foreach ($journey->legs() as [$flight]) {
            if (isset($seen[$flight->id])) {
                throw new InvalidJourney("{$flight->flight_no} is in the journey twice.");
            }
            $seen[$flight->id] = true;

            if (in_array($flight->status, [FlightStatus::Cancelled, FlightStatus::Closed, FlightStatus::Departed], true)) {
                throw new InvalidJourney("{$flight->flight_no} is {$flight->status->label()}: it cannot be booked.");
            }
        }

        foreach (SegmentDirection::cases() as $direction) {
            $flights = $journey->flights($direction);

            if (count($flights) > self::MAX_FLIGHTS_PER_DIRECTION) {
                throw new InvalidJourney('At most '.self::MAX_FLIGHTS_PER_DIRECTION.' flights in each direction.');
            }

            for ($i = 1; $i < count($flights); $i++) {
                $this->ensureConnects($flights[$i - 1], $flights[$i]);
            }
        }

        if ($journey->return !== []) {
            $this->ensureRoundTrip($journey->outbound, $journey->return);
        }
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

        if ($journey->outbound[0]->id !== $reservation->flight_id) {
            throw new InvalidJourney("The journey must start with {$reservation->flight->flight_no}, the reservation's flight.");
        }

        DB::transaction(function () use ($reservation, $journey) {
            $sequence = (int) $reservation->segments()->max('sequence');
            $passengers = $reservation->passengers()->get();

            foreach (array_slice($journey->legs(), 1) as [$flight, $direction]) {
                $segment = $reservation->segments()->create([
                    'flight_id' => $flight->id,
                    'sequence' => ++$sequence,
                    'direction' => $direction,
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
     * @return Collection<int, ReservationSegment>
     */
    private function segments(Reservation $reservation): Collection
    {
        return $reservation->relationLoaded('segments') && $reservation->segments->every(fn ($segment) => $segment->relationLoaded('flight'))
            ? $reservation->segments
            : $reservation->load('segments.flight')->segments;
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

        $this->ensureEnoughTime($lastOut, $firstBack);
    }

    /**
     * The next flight leaves at least the connecting airport's minimum connection time after landing.
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
}
