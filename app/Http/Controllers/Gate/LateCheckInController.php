<?php

namespace App\Http\Controllers\Gate;

use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\ExcessBaggageUnpaid;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\ManagerApprovalRefused;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gate\LateCheckInRequest;
use App\Models\Passenger;
use App\Models\Seat;
use App\Services\EntryRequirementChecker;
use App\Services\JourneyService;
use App\Services\LateCheckInService;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Late passenger override (G5, SR-29): a Booked passenger at the gate after check-in has closed.
 */
class LateCheckInController extends Controller
{
    public function create(Request $request, Passenger $passenger, SeatService $seats, EntryRequirementChecker $entryCheck, JourneyService $journeys): View|RedirectResponse
    {
        // The first flight of the direction leaving from the gate agent's station (B13b).
        $flight = $journeys->firstFlightFor($passenger, $request->user());
        $passenger->load([
            'reservation.flight',
            'coupon' => fn ($query) => $query->where('coupons.flight_id', $flight->id),
            'seatAssignment' => fn ($query) => $query->where('seat_assignments.flight_id', $flight->id),
            'seatAssignment.seat',
        ]);

        if ($passenger->status !== PassengerStatus::Booked || ! $flight->isLateCheckInOpen()) {
            return redirect()->route('gate.flights.show', $flight)
                ->with('error', session('error', 'Late check-in is not available for this passenger.'));
        }

        $needsSeat = $passenger->type !== PassengerType::Infant && $passenger->seatAssignment === null;

        return view('gate.late-checkin', [
            'passenger' => $passenger,
            'flight' => $flight,
            'seatMap' => $needsSeat ? $seats->seatMap($flight) : null,
            'entryResult' => $entryCheck->check($passenger),
        ]);
    }

    public function store(LateCheckInRequest $request, Passenger $passenger, LateCheckInService $lateCheckIn): RedirectResponse
    {
        try {
            $lateCheckIn->complete(
                $passenger,
                $request->user(),
                $request->validated('manager_email'),
                $request->validated('password'),
                $request->validated('reason'),
                Seat::query()->find($request->validated('seat_id')),
                $request->validated('entry_reason'),
            );
        } catch (ManagerApprovalRefused|InvalidStatusChange|SeatTaken|SeatNotAllowed|ExcessBaggageUnpaid $exception) {
            return redirect()->route('gate.passengers.late-checkin', $passenger)
                ->withInput($request->except('password'))
                ->with('error', $exception->getMessage());
        } catch (EntryReasonRequired $exception) {
            return redirect()->route('gate.passengers.late-checkin', $passenger)
                ->withInput($request->except('password'))
                ->withErrors(['entry_reason' => $exception->getMessage()]);
        }

        $flight = app(JourneyService::class)->firstFlightFor($passenger, $request->user());

        return redirect()->route('gate.flights.show', $flight)
            ->with('success', $passenger->first_name.' '.$passenger->last_name.' checked in late. Boarding pass issued.')
            ->with('boardingPassUrl', route('gate.flights.boarding-pass', [$flight, $passenger]));
    }
}
