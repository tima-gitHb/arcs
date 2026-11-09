<?php

namespace App\Services;

use App\Enums\BagHandling;
use App\Enums\PassengerStatus;
use App\Exceptions\PnrNotOnFlight;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The gate manifest (G1: SR-11, SR-45). Read-only: status changes stay in StatusService.
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
        $byStatus = Passenger::query()
            ->join('reservations', 'reservations.id', '=', 'passengers.reservation_id')
            ->where('reservations.flight_id', $flight->id)
            ->groupBy('passengers.status')
            ->selectRaw('passengers.status as status, count(*) as total')
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
        $rows = Passenger::query()
            ->join('reservations', 'reservations.id', '=', 'passengers.reservation_id')
            ->join('seat_assignments', 'seat_assignments.passenger_id', '=', 'passengers.id')
            ->join('seats', 'seats.id', '=', 'seat_assignments.seat_id')
            ->where('reservations.flight_id', $flight->id)
            ->where('passengers.status', '!=', PassengerStatus::Cancelled)
            ->get(['seats.seat_no', 'passengers.status']);

        $states = [];
        foreach ($rows as $row) {
            $states[$row->seat_no] = $row->status instanceof PassengerStatus
                ? $row->status->value
                : (string) $row->status;
        }

        return $states;
    }

    /**
     * Every passenger on the flight except cancelled ones, with everything the manifest shows
     * eager-loaded, so the page runs a fixed number of queries for any flight size.
     *
     * @return Collection<int, Passenger>
     */
    public function passengers(Flight $flight): Collection
    {
        return Passenger::query()
            ->whereHas('reservation', fn ($query) => $query->where('flight_id', $flight->id))
            ->where('status', '!=', PassengerStatus::Cancelled)
            ->with([
                'reservation',
                'accompanyingAdult',
                'seatAssignment.seat',
                'checkedInBy.user',
                'boardedBy.user',
                'specialServices',
                // Items handed back at the aircraft door (wheelchair, stroller…).
                'bags' => fn ($query) => $query->where('handling', BagHandling::GateDelivery)->orderBy('id'),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Bags to take off the aircraft after boarding is closed (G3, SR-9): every bag of the
     * flight's NoShow passengers. Offloaded ones stay on the list, shown as done (G4, SR-28).
     *
     * @return Collection<int, Bag>
     */
    public function bagsToOffload(Flight $flight): Collection
    {
        return Bag::query()
            ->whereHas('passenger', fn ($passenger) => $passenger
                ->where('status', PassengerStatus::NoShow)
                ->whereHas('reservation', fn ($reservation) => $reservation->where('flight_id', $flight->id)))
            ->with(['passenger.seatAssignment.seat', 'offloadedBy'])
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
        $reservation = Reservation::query()->with('flight')->where('pnr', $pnr)->first();

        if ($reservation === null) {
            throw new PnrNotOnFlight("No reservation with PNR {$pnr}.");
        }
        if ($reservation->flight_id !== $flight->id) {
            throw new PnrNotOnFlight("PNR {$pnr} is booked on {$reservation->flight->flight_no}, not on this flight.");
        }

        return $reservation->passengers()
            ->with(['reservation', 'seatAssignment.seat'])
            ->where('status', '!=', PassengerStatus::Cancelled)
            ->orderBy('id')
            ->get();
    }
}
