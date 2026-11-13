<?php

namespace App\Http\Controllers\Manager;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreFlightRequest;
use App\Http\Requests\Manager\UpdateFlightGateRequest;
use App\Http\Requests\Manager\UpdateFlightStatusRequest;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Services\StatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FlightController extends Controller
{
    public function index(StatusService $statusService): View
    {
        $flights = Flight::with('aircraft')->orderByDesc('departure_at')->get();

        return view('manager.flights', [
            'flights' => $flights,
            'aircraft' => Aircraft::withCount('seats')->orderBy('registration')->get(),
            'airports' => Airport::orderBy('code')->get(),
            // flight id => the statuses StatusService would accept: one button each.
            'moves' => $flights->mapWithKeys(fn (Flight $flight) => [
                $flight->id => $statusService->allowedFlightMoves($flight),
            ]),
        ]);
    }

    public function store(StoreFlightRequest $request): RedirectResponse
    {
        $flight = Flight::create($request->flightData());

        return redirect()->route('manager.flights')
            ->with('success', "Flight {$flight->flight_no} created.");
    }

    /**
     * Every flight status change goes through StatusService (SR-25). If it refuses, show its message.
     */
    public function updateStatus(UpdateFlightStatusRequest $request, Flight $flight, StatusService $statusService): RedirectResponse
    {
        $new = FlightStatus::from($request->validated('status'));

        try {
            $statusService->setFlightStatus($flight, $new, $request->user());
        } catch (InvalidStatusChange $e) {
            return redirect()->route('manager.flights')->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('manager.flights')
            ->with('success', "Flight {$flight->flight_no} is now {$new->label()}.");
    }

    /**
     * An empty gate clears it (SR-42). A real change is recorded on the flight (SR-55).
     */
    public function updateGate(UpdateFlightGateRequest $request, Flight $flight): RedirectResponse
    {
        $new = $request->validated('gate');

        DB::transaction(function () use ($flight, $new, $request) {
            $old = $flight->gate;

            if ($old === $new) {
                return;
            }

            $flight->update(['gate' => $new]);

            // The audit column cannot store null. A cleared gate is an empty string, shown as a dash.
            AuditEntry::forFlight($flight, 'gate', $old, $new ?? '', $request->user());
        });

        return redirect()->route('manager.flights')
            ->with('success', "Gate for {$flight->flight_no} saved.");
    }
}
