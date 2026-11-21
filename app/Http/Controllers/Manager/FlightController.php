<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreFlightRequest;
use App\Http\Requests\Manager\UpdateFlightGateRequest;
use App\Http\Requests\Manager\UpdateFlightStatusRequest;
use App\Models\Aircraft;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Services\FlightNotifier;
use App\Services\SeatService;
use App\Services\StatusService;
use App\Support\ManagerStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FlightController extends Controller
{
    public function index(StatusService $statusService, Request $request): View
    {
        $station = ManagerStation::apply($request);
        $status = $request->query('status');
        $date = $request->query('date');
        $q = trim((string) $request->query('q', ''));

        $flights = Flight::with('aircraft')
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->when(is_string($date) && $date !== '', function ($query) use ($date, $request) {
                [$start, $end] = $request->user()->stationDay($date);
                $query->whereBetween('departure_at', [$start, $end]);
            })
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('flight_no', 'like', "%{$q}%")
                    ->orWhere('origin', 'like', "%{$q}%")
                    ->orWhere('destination', 'like', "%{$q}%");
            }))
            ->orderByDesc('departure_at')
            ->paginate(25)
            ->withQueryString();

        $statusCounts = Flight::query()
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('manager.flights', [
            'flights' => $flights,
            'station' => $station,
            'statusCounts' => $statusCounts,
            'filters' => [
                'q' => $q,
                'date' => is_string($date) ? $date : '',
                'status' => is_string($status) ? $status : '',
            ],
            'moves' => collect(),
        ]);
    }

    public function create(): View
    {
        ManagerStation::apply(request());

        return view('manager.flights-create', [
            'aircraft' => Aircraft::withCount('seats')->orderBy('registration')->get(),
            'airports' => ManagerStation::airports(request()),
        ]);
    }

    public function show(Flight $flight, StatusService $statusService, SeatService $seatService, Request $request): View
    {
        ManagerStation::apply($request);
        $tab = $request->query('tab', 'overview');
        if (! in_array($tab, ['overview', 'load', 'status'], true)) {
            $tab = 'overview';
        }

        $flight->load(['aircraft.seats', 'originAirport', 'destinationAirport']);

        $passengerCounts = DB::table('coupons')
            ->where('flight_id', $flight->id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (PassengerStatus $status) => (int) ($passengerCounts[$status->value] ?? 0);
        $checkedIn = $count(PassengerStatus::CheckedIn) + $count(PassengerStatus::Boarded) + $count(PassengerStatus::NoShow);
        $booked = $count(PassengerStatus::Booked);
        $total = $checkedIn + $booked;

        $bags = (int) DB::table('bags')
            ->join('coupons', 'coupons.passenger_id', '=', 'bags.passenger_id')
            ->join('reservation_segments', fn ($join) => $join
                ->on('reservation_segments.id', '=', 'coupons.reservation_segment_id')
                ->on('reservation_segments.direction', '=', 'bags.direction'))
            ->where('coupons.flight_id', $flight->id)
            ->count();

        $seatStates = DB::table('seat_assignments')
            ->join('seats', 'seats.id', '=', 'seat_assignments.seat_id')
            ->join('coupons', function ($join) use ($flight) {
                $join->on('coupons.passenger_id', '=', 'seat_assignments.passenger_id')
                    ->where('coupons.flight_id', '=', $flight->id);
            })
            ->where('seat_assignments.flight_id', $flight->id)
            ->pluck('coupons.status', 'seats.seat_no');

        $cabinLoad = collect(CabinClass::cases())->mapWithKeys(function (CabinClass $cabin) use ($flight) {
            $seats = $flight->aircraft->seats->where('cabin_class', $cabin)->count();
            $sold = DB::table('seat_assignments')
                ->join('seats', 'seats.id', '=', 'seat_assignments.seat_id')
                ->where('seat_assignments.flight_id', $flight->id)
                ->where('seats.cabin_class', $cabin->value)
                ->count();

            return [$cabin->label() => ['seats' => $seats, 'sold' => $sold]];
        });

        return view('manager.flights-show', [
            'flight' => $flight,
            'tab' => $tab,
            'moves' => $statusService->allowedFlightMoves($flight),
            'counters' => [
                'booked' => $booked,
                'checked_in' => $checkedIn,
                'bags' => $bags,
                'load' => $total > 0 ? (int) round($checkedIn / $total * 100) : 0,
                'total' => $total,
            ],
            'seatMap' => $seatService->seatMap($flight),
            'seatStates' => $seatStates,
            'cabinLoad' => $cabinLoad,
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

        return redirect()->route('manager.flights.show', $flight)
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
            return redirect()->route('manager.flights.show', ['flight' => $flight, 'tab' => 'status'])
                ->withErrors(['status' => $e->getMessage()]);
        }

        if ($departure !== null) {
            $notifier->delayed($flight);
        }

        return redirect()->route('manager.flights.show', ['flight' => $flight, 'tab' => 'status'])
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

        return redirect()->route('manager.flights.show', ['flight' => $flight, 'tab' => 'status'])
            ->with('success', "Gate for {$flight->flight_no} saved.");
    }
}
