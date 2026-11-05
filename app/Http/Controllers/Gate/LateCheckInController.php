<?php

namespace App\Http\Controllers\Gate;

use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\ManagerApprovalRefused;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gate\LateCheckInRequest;
use App\Models\Passenger;
use App\Models\Seat;
use App\Services\EntryRequirementChecker;
use App\Services\LateCheckInService;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Late passenger override (G5, SR-29): a Booked passenger at the gate after check-in has closed.
 */
class LateCheckInController extends Controller
{
    public function create(Passenger $passenger, SeatService $seats, EntryRequirementChecker $entryCheck): View|RedirectResponse
    {
        $passenger->load(['reservation.flight', 'seatAssignment.seat']);
        $flight = $passenger->reservation->flight;

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
        } catch (ManagerApprovalRefused|InvalidStatusChange|SeatTaken|SeatNotAllowed $exception) {
            return redirect()->route('gate.passengers.late-checkin', $passenger)
                ->withInput($request->except('password'))
                ->with('error', $exception->getMessage());
        } catch (EntryReasonRequired $exception) {
            return redirect()->route('gate.passengers.late-checkin', $passenger)
                ->withInput($request->except('password'))
                ->withErrors(['entry_reason' => $exception->getMessage()]);
        }

        return redirect()->route('gate.flights.show', $passenger->reservation->flight_id)
            ->with('success', $passenger->first_name.' '.$passenger->last_name.' checked in late. Boarding pass issued.')
            ->with('boardingPassUrl', route('gate.flights.boarding-pass', [$passenger->reservation->flight_id, $passenger]));
    }
}
