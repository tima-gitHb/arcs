<?php

namespace App\Http\Controllers\Manager;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreFlightScheduleRequest;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Services\GenerateFlightsFromSchedule;
use App\Services\StatusService;
use App\Support\ManagerStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class FlightScheduleController extends Controller
{
    public function index(): View
    {
        ManagerStation::apply(request());

        $schedules = FlightSchedule::query()
            ->with(['aircraft'])
            ->withCount('flights')
            ->orderBy('flight_no')
            ->get();

        return view('manager.schedules', ['schedules' => $schedules]);
    }

    public function create(): View
    {
        ManagerStation::apply(request());

        return view('manager.schedules-form', [
            'schedule' => new FlightSchedule([
                'checkin_opens_minutes_before' => 180,
                'checkin_closes_minutes_before' => 45,
                'block_minutes' => 240,
                'active' => true,
                'days_of_week' => [],
            ]),
            'aircraft' => Aircraft::query()->orderBy('registration')->get(),
            'airports' => ManagerStation::airports(request()),
            'editing' => false,
        ]);
    }

    public function store(StoreFlightScheduleRequest $request): RedirectResponse
    {
        $schedule = FlightSchedule::create($request->scheduleData());

        AuditEntry::create([
            'field' => 'flight_schedule_created',
            'old_value' => null,
            'new_value' => mb_substr($schedule->flight_no.' '.$schedule->origin.'→'.$schedule->destination, 0, 255),
            'user_id' => $request->user()->id,
            'changed_at' => now(),
        ]);

        return redirect()
            ->route('manager.schedules.show', $schedule)
            ->with('success', "Schedule {$schedule->flight_no} created.");
    }

    public function show(FlightSchedule $schedule): View
    {
        ManagerStation::apply(request());

        $schedule->load(['aircraft', 'flights' => fn ($q) => $q->orderBy('departure_at')->withCount('coupons')]);

        return view('manager.schedules-show', [
            'schedule' => $schedule,
            'generateResult' => session('generate_result'),
            'bookedLeft' => session('booked_left', collect()),
        ]);
    }

    public function edit(FlightSchedule $schedule): View
    {
        ManagerStation::apply(request());

        return view('manager.schedules-form', [
            'schedule' => $schedule,
            'aircraft' => Aircraft::query()->orderBy('registration')->get(),
            'airports' => ManagerStation::airports(request()),
            'editing' => true,
        ]);
    }

    public function update(
        StoreFlightScheduleRequest $request,
        FlightSchedule $schedule,
        GenerateFlightsFromSchedule $generator,
    ): RedirectResponse {
        $before = $schedule->only([
            'flight_no', 'origin', 'destination', 'aircraft_id', 'days_of_week',
            'departure_local_time', 'block_minutes', 'gate', 'valid_from', 'valid_to', 'active',
        ]);

        $schedule->update($request->scheduleData());

        AuditEntry::create([
            'field' => 'flight_schedule_updated',
            'old_value' => mb_substr(json_encode($before), 0, 255),
            'new_value' => mb_substr($schedule->flight_no.' updated', 0, 255),
            'user_id' => $request->user()->id,
            'changed_at' => now(),
        ]);

        $sync = $generator->syncFutureUnbooked($schedule->fresh(), $request->user());

        return redirect()
            ->route('manager.schedules.show', $schedule)
            ->with('success', "Schedule saved. {$sync['updated']} future unbooked flight(s) updated.")
            ->with('booked_left', $sync['booked']);
    }

    public function generate(
        Request $request,
        FlightSchedule $schedule,
        GenerateFlightsFromSchedule $generator,
    ): RedirectResponse {
        if (! $schedule->active) {
            return back()->with('error', 'This schedule is inactive: activate it before generating flights.');
        }

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $originTz = Airport::timezoneOf($schedule->origin);
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'), $originTz)->startOfDay()
            : now($originTz)->startOfDay();
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'), $originTz)->startOfDay()
            : $from->copy()->addWeeks(8);

        $result = $generator->generate($schedule, $from, $to, $request->user());

        return redirect()
            ->route('manager.schedules.show', $schedule)
            ->with('success', "Created {$result['created']} · skipped {$result['skipped']} · clash {$result['clashes']}.")
            ->with('generate_result', $result);
    }

    public function cancelDate(
        Request $request,
        FlightSchedule $schedule,
        Flight $flight,
        StatusService $statuses,
    ): RedirectResponse {
        if ((int) $flight->flight_schedule_id !== (int) $schedule->id) {
            abort(404);
        }

        try {
            $statuses->setFlightStatus($flight, FlightStatus::Cancelled, $request->user());
        } catch (InvalidStatusChange $exception) {
            return back()->with('error', $exception->getMessage());
        }

        AuditEntry::create([
            'field' => 'flight_schedule_cancel_date',
            'old_value' => null,
            'new_value' => mb_substr($flight->flight_no.' '.$flight->departureLocal()->toDateString(), 0, 255),
            'user_id' => $request->user()->id,
            'changed_at' => now(),
            'flight_id' => $flight->id,
        ]);

        return back()->with('success', "{$flight->flight_no} on {$flight->departureLocal()->toDateString()} cancelled.");
    }
}
