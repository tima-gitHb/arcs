<?php

namespace App\Http\Controllers\Manager;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreFlightRequest;
use App\Http\Requests\Manager\UpdateFlightGateRequest;
use App\Http\Requests\Manager\UpdateFlightStatusRequest;
use App\Models\Aircraft;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Services\FlightNotifier;
use App\Services\StatusService;
use App\Support\ManagerStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FlightController extends Controller
{
    public function index(StatusService $statusService): View
    {
        $station = ManagerStation::apply(request());
        $flights = Flight::with('aircraft')
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->orderByDesc('departure_at')
            ->get();

        return view('manager.flights', [
            'flights' => $flights,
            'aircraft' => Aircraft::withCount('seats')->orderBy('registration')->get(),
            'airports' => ManagerStation::airports(request()),
            'station' => $station,
            // flight id => the statuses StatusService would accept: one button each.
            'moves' => $flights->mapWithKeys(fn (Flight $flight) => [
                $flight->id => $statusService->allowedFlightMoves($flight),
            ]),
        ]);
    }

    public function store(StoreFlightRequest $request): RedirectResponse
    {
        $flight = Flight::create($request->flightData());

        $message = "Flight {$flight->flight_no} created.";
        $station = ManagerStation::apply($request);
        if ($station !== null && $flight->origin !== $station) {
            $message .= " It departs from {$flight->origin}, not {$station}, so this list does not show it.";
        }

        return redirect()->route('manager.flights')
            ->with('success', $message);
    }

    /**
     * Every flight status change goes through StatusService (SR-25). If it refuses, show its message.
     * A Delayed move may also carry a new departure. That time is saved in the same transaction
     * as the status, with its own flight audit row. The email is sent only after the commit.
     */
    public function updateStatus(UpdateFlightStatusRequest $request, Flight $flight, StatusService $statusService, FlightNotifier $notifier): RedirectResponse
    {
        $new = FlightStatus::from($request->validated('status'));
        $departure = $request->newDeparture();

        try {
            if ($departure !== null) {
                DB::transaction(function () use ($flight, $new, $request, $statusService, $departure) {
                    $statusService->setFlightStatus($flight, $new, $request->user());

                    $old = $flight->departureLocal()->format('d M Y H:i');
                    $flight->update(['departure_at' => $departure]);

                    AuditEntry::forFlight(
                        $flight,
                        'departure_at',
                        $old,
                        $flight->departureLocal()->format('d M Y H:i'),
                        $request->user(),
                    );
                });
            } else {
                $statusService->setFlightStatus($flight, $new, $request->user());
            }
        } catch (InvalidStatusChange $e) {
            return redirect()->route('manager.flights')->withErrors(['status' => $e->getMessage()]);
        }

        if ($departure !== null) {
            $notifier->delayed($flight);
        }

        return redirect()->route('manager.flights')
            ->with('success', "Flight {$flight->flight_no} is now {$new->label()}.");
    }

    /**
     * An empty gate clears it (SR-42). A real change is recorded on the flight (SR-55).
     * Only a new non-empty gate is emailed, and only after this transaction commits.
     */
    public function updateGate(UpdateFlightGateRequest $request, Flight $flight, FlightNotifier $notifier): RedirectResponse
    {
        $new = $request->validated('gate');
        $emailNewGate = false;

        DB::transaction(function () use ($flight, $new, $request, &$emailNewGate) {
            $old = $flight->gate;

            if ($old === $new) {
                return;
            }

            $flight->update(['gate' => $new]);

            // The audit column cannot store null. A cleared gate is an empty string, shown as a dash.
            AuditEntry::forFlight($flight, 'gate', $old, $new ?? '', $request->user());

            $emailNewGate = filled($new);
        });

        if ($emailNewGate) {
            $notifier->gateChanged($flight);
        }

        return redirect()->route('manager.flights')
            ->with('success', "Gate for {$flight->flight_no} saved.");
    }
}
