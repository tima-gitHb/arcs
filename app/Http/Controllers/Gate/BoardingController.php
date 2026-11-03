<?php

namespace App\Http\Controllers\Gate;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\PnrNotOnFlight;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Services\ManifestService;
use App\Services\StatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Boarding at the gate (G2: SR-10). StatusService decides every status change;
 * this only finds the passenger and words the result.
 */
class BoardingController extends Controller
{
    public function board(Request $request, Flight $flight, ManifestService $manifest, StatusService $statuses): RedirectResponse
    {
        $request->validate([
            'pnr' => ['required', 'string', 'max:10'],
            'passenger_id' => ['nullable', 'integer'],
        ]);

        $pnr = strtoupper(trim((string) $request->input('pnr')));
        $back = redirect()->route('gate.flights.show', $flight);

        try {
            $candidates = $manifest->passengersForPnr($flight, $pnr);
        } catch (PnrNotOnFlight $exception) {
            return $back->with('error', $exception->getMessage());
        }

        if ($request->filled('passenger_id')) {
            $passenger = $candidates->firstWhere('id', $request->integer('passenger_id'));

            if ($passenger === null) {
                return $back->with('error', "That passenger is not on PNR {$pnr}.");
            }
        } elseif ($candidates->count() > 1) {
            // Several passengers share the PNR: the manifest asks the agent to choose.
            return redirect()->route('gate.flights.show', [$flight, 'pnr' => $pnr]);
        } elseif ($candidates->isEmpty()) {
            return $back->with('error', "No passenger to board on PNR {$pnr}.");
        } else {
            $passenger = $candidates->first();
        }

        try {
            $statuses->board($passenger, $request->user());
        } catch (InvalidStatusChange $exception) {
            return $back->with('error', $exception->getMessage());
        }

        $seat = $passenger->seatAssignment?->seat->seat_no;

        return $back->with('success', $passenger->first_name.' '.$passenger->last_name.' boarded, '
            .($seat ? "seat {$seat}." : 'infant, no seat.'));
    }

    /**
     * "Yes, start boarding": CheckinOpen → Boarding through the same StatusService method
     * the Manager's flights page uses (M1), with the gate user as actor.
     */
    public function start(Request $request, Flight $flight, StatusService $statuses): RedirectResponse
    {
        $back = redirect()->route('gate.flights.show', $flight);

        try {
            $statuses->setFlightStatus($flight, FlightStatus::Boarding, $request->user());
        } catch (InvalidStatusChange $exception) {
            return $back->with('error', $exception->getMessage());
        }

        return $back->with('success', "Boarding started for {$flight->flight_no}.");
    }
}
