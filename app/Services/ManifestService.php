<?php

namespace App\Services;

use App\Enums\BagHandling;
use App\Enums\PassengerStatus;
use App\Exceptions\PnrNotOnFlight;
use App\Models\Bag;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\SeatAssignment;
use App\Support\ConnectionTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The gate manifest (G1: SR-11, SR-45). Read-only: status changes stay in StatusService.
 * Since B13b everything is per flight through coupons: a connecting passenger is on the
 * manifest of each of their flights, with that flight's status, seat and "who did it".
 */
class ManifestService
{
    /**
     * Counts for the manifest header and the 10-second refresh (SR-11), in one query.
     *   checked_in: got through check-in (CheckedIn, Boarded, or NoShow after closing)
     *   boarded:    Boarded
     *   missing:    checked in but not boarded
     *
     * @return array{checked_in: int, boarded: int, missing: int}
     */
    public function counts(Flight $flight): array
    {
        $byStatus = Coupon::query()
            ->where('flight_id', $flight->id)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $boarded = (int) ($byStatus[PassengerStatus::Boarded->value] ?? 0);
        $missing = (int) ($byStatus[PassengerStatus::CheckedIn->value] ?? 0)
            + (int) ($byStatus[PassengerStatus::NoShow->value] ?? 0);

        return [
            'checked_in' => $boarded + $missing,
            'boarded' => $boarded,
            'missing' => $missing,
        ];
    }

    /**
     * Seat number → passenger status for the live gate plane (no names or documents).
     *
     * @return array<string, string>
     */
    public function seatStates(Flight $flight): array
    {
        $rows = SeatAssignment::query()
            ->join('coupons', 'coupons.id', '=', 'seat_assignments.coupon_id')
            ->join('seats', 'seats.id', '=', 'seat_assignments.seat_id')
            ->where('seat_assignments.flight_id', $flight->id)
            ->whereNotIn('coupons.status', [PassengerStatus::Cancelled->value, PassengerStatus::Exchanged->value])
            ->toBase()
            ->get(['seats.seat_no', 'coupons.status']);

        $states = [];
        foreach ($rows as $row) {
            $states[$row->seat_no] = (string) $row->status;
        }

        return $states;
    }

    /**
     * Every passenger on the flight except cancelled ones, with everything the manifest shows
     * eager-loaded for this flight, so the page runs a fixed number of queries for any flight size.
     *
     * @return Collection<int, Passenger>
     */
    public function passengers(Flight $flight): Collection
    {
        return $this->onFlight(Passenger::query(), $flight)
            ->with([
                'reservation.segments.flight.destinationAirport',
                'accompanyingAdult',
                'specialServices',
                // Items handed back at the aircraft door (wheelchair, stroller…).
                'bags' => fn ($query) => $query->where('handling', BagHandling::GateDelivery)->orderBy('id'),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->each(function (Passenger $passenger) use ($flight) {
                $this->showWhoDidIt($passenger);
                $passenger->setAttribute('connection', $this->connectionInfo($passenger, $flight));
            });
    }

    /**
     * Inbound connection on this flight (G7 / SR-81): previous same-direction segment, arrival,
     * ConnectionTime label, and a tight flag when the gap is under the airport's MCT.
     *
     * @return array{flight_no: string, arrives_at: Carbon|null, airport: string, label: string|null, tight: bool}|null
     */
    public function connectionInfo(Passenger $passenger, Flight $flight): ?array
    {
        $segments = $passenger->reservation->segments->sortBy('sequence')->values();
        $index = $segments->search(fn ($segment) => $segment->flight_id === $flight->id);

        if ($index === false || $index < 1) {
            return null;
        }

        $current = $segments[$index];
        $previous = $segments[$index - 1];

        if ($previous->direction !== $current->direction) {
            return null;
        }

        $inbound = $previous->flight;

        if ($inbound->destination !== $flight->origin) {
            return null;
        }

        return [
            'flight_no' => $inbound->flight_no,
            'arrives_at' => $inbound->arrives_at,
            'airport' => $inbound->destination,
            'label' => ConnectionTime::label($inbound, $flight),
            'tight' => ConnectionTime::isTight($inbound, $flight),
        ];
    }

    /**
     * Bags to take off the aircraft after boarding is closed (G3, SR-9): every bag the flight's
     * NoShow passengers checked in that direction. Offloaded ones stay on the list, shown as done (G4, SR-28).
     *
     * @return Collection<int, Bag>
     */
    public function bagsToOffload(Flight $flight): Collection
    {
        return Bag::query()
            ->noShowOn($flight)
            ->with([
                'passenger.seatAssignment' => fn ($query) => $query->where('seat_assignments.flight_id', $flight->id),
                'passenger.seatAssignment.seat',
                'offloadedBy',
            ])
            ->orderBy('passenger_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The passengers (not cancelled) of the PNR printed on a boarding pass, on this flight only (G2).
     *
     * @return Collection<int, Passenger>
     *
     * @throws PnrNotOnFlight unknown PNR, or booked on another flight
     */
    public function passengersForPnr(Flight $flight, string $pnr): Collection
    {
        $pnr = strtoupper(trim($pnr));
        $reservation = Reservation::query()->with(['flight', 'segments'])->where('pnr', $pnr)->first();

        if ($reservation === null) {
            throw new PnrNotOnFlight("No reservation with PNR {$pnr}.");
        }
        if (! $reservation->segments->contains('flight_id', $flight->id)) {
            throw new PnrNotOnFlight("PNR {$pnr} is booked on {$reservation->flight->flight_no}, not on this flight.");
        }

        return $this->onFlight($reservation->passengers(), $flight)
            ->with('reservation')
            ->orderBy('id')
            ->get();
    }

    /**
     * Passengers with a coupon on the flight that is not cancelled, with that coupon as their current
     * one (so ->status is this flight's) and this flight's seat.
     *
     * @template TQuery of Builder|\Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function onFlight($query, Flight $flight)
    {
        return $query
            ->whereHas('coupons', fn ($coupon) => $coupon
                ->where('coupons.flight_id', $flight->id)
                ->whereNotIn('coupons.status', [PassengerStatus::Cancelled->value, PassengerStatus::Exchanged->value]))
            ->with([
                'coupon' => fn ($coupon) => $coupon->where('coupons.flight_id', $flight->id),
                'coupon.checkedInBy.user',
                'coupon.boardedBy.user',
                'seatAssignment' => fn ($seat) => $seat->where('seat_assignments.flight_id', $flight->id),
                'seatAssignment.seat',
            ]);
    }

    /**
     * "Checked in by / boarded by" (SR-45) for this flight's coupon, not the passenger's latest anywhere.
     */
    private function showWhoDidIt(Passenger $passenger): void
    {
        $passenger->setRelation('checkedInBy', $passenger->coupon?->checkedInBy);
        $passenger->setRelation('boardedBy', $passenger->coupon?->boardedBy);
    }
}
