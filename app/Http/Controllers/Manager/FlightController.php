<?php

namespace App\Http\Controllers\Manager;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreFlightRequest;
use App\Http\Requests\Manager\UpdateFlightGateRequest;
use App\Http\Requests\Manager\UpdateFlightStatusRequest;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Services\StatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FlightController extends Controller
{
    public function index(StatusService $statusService): View
    {
        $flights = Flight::with('aircraft')->orderByDesc('departure_at')->get();

        return view('manager.flights', [
            'flights' => $flights,
            'aircraft' => Aircraft::withCount('seats')->orderBy('registration')->get(),
            // flight id => the statuses StatusService would accept: one button each.
            'moves' => $flights->mapWithKeys(fn (Flight $flight) => [
                $flight->id => $statusService->allowedFlightMoves($flight),
            ]),
        ]);
    }

    public function store(StoreFlightRequest $request): RedirectResponse
    {
        $flight = Flight::create($request->validated());

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
     * An empty gate clears it (SR-42).
     */
    public function updateGate(UpdateFlightGateRequest $request, Flight $flight): RedirectResponse
    {
        $flight->update(['gate' => $request->validated('gate')]);

        return redirect()->route('manager.flights')
            ->with('success', "Gate for {$flight->flight_no} saved.");
    }
}
