<?php

namespace App\Services;

use App\Enums\BagHandling;
use App\Enums\PassengerStatus;
use App\Models\Flight;
use App\Models\Passenger;
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
}
