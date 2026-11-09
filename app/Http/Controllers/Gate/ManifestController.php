<?php

namespace App\Http\Controllers\Gate;

use App\Enums\FlightStatus;
use App\Exceptions\PnrNotOnFlight;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Services\ManifestService;
use App\Services\SeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Live manifest of one flight (G1: SR-11, SR-45).
 */
class ManifestController extends Controller
{
    public function show(Request $request, Flight $flight, ManifestService $manifest): View
    {
        // ?pnr=… after a PNR shared by several passengers: the agent picks one (G2).
        $choosing = null;
        if ($request->filled('pnr') && $flight->status === FlightStatus::Boarding) {
            try {
                $choosing = $manifest->passengersForPnr($flight, (string) $request->query('pnr'));
            } catch (PnrNotOnFlight) {
                $choosing = null;
            }
        }

        return view('gate.manifest', [
            'flight' => $flight,
            'counts' => $manifest->counts($flight),
            'passengers' => $manifest->passengers($flight),
            'seatStates' => $manifest->seatStates($flight),
            'seatMap' => app(SeatService::class)->seatMap($flight),
            'choosing' => $choosing,
            'choosingPnr' => strtoupper((string) $request->query('pnr')),
            // ?start=confirm / ?close=confirm show the on-page confirmation first (G2, G3).
            'confirmingStart' => $request->query('start') === 'confirm' && $flight->status === FlightStatus::CheckinOpen,
            'confirmingClose' => $request->query('close') === 'confirm' && $flight->status === FlightStatus::Boarding,
            'offload' => in_array($flight->status, [FlightStatus::Closed, FlightStatus::Departed], true)
                ? $manifest->bagsToOffload($flight)
                : collect(),
        ]);
    }

    /**
     * Polled every 10 seconds by the manifest page (counts + seat states for the plane).
     */
    public function counts(Flight $flight, ManifestService $manifest): JsonResponse
    {
        return response()->json($manifest->counts($flight) + [
            'status' => $flight->status->value,
            'seats' => $manifest->seatStates($flight),
        ]);
    }
}
