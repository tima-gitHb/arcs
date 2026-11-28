<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\ExcessBaggageUnpaid;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkin\UpdatePassengerEmailRequest;
use App\Models\Passenger;
use App\Models\SeatAssignment;
use App\Services\BoardingPassService;
use App\Services\EntryRequirementChecker;
use App\Services\JourneyService;
use App\Services\PassengerService;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckinController extends Controller
{
    private const MAX_RESULTS = 50;

    /**
     * Check-in home: today's flights (SR-41) and a search by PNR or last name.
     */
    public function home(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $reservations = collect();

        if ($search !== '') {
            $reservations = Passenger::query()
                ->with('reservation.flight')
                ->where(fn ($query) => $query
                    ->whereHas('reservation', fn ($reservation) => $reservation->where('pnr', strtoupper($search)))
                    ->orWhere('last_name', 'like', $search.'%'))
                // Only flights from the agent's station (B13a), through any flight of the journey
                // (B13b: the return of a trip is checked in at its own origin). Flights that left
                // before today (at the station) are not checked in any more — unless their check-in
                // window is still open (BUG-013 / overnight departures).
                ->whereHas('coupons.flight', fn ($flight) => $flight
                    ->departingFrom($request->user()->airport_code)
                    ->where(function ($board) use ($request) {
                        $board->where('departure_at', '>=', $request->user()->stationDay()[0])
                            ->orWhere(function ($window) {
                                $window->where('checkin_opens_at', '<=', now())
                                    ->where('checkin_closes_at', '>=', now());
                            });
                    }))
                ->orderBy('id')
                ->limit(self::MAX_RESULTS)
                ->get()
                ->groupBy('reservation_id')
                ->map(fn ($passengers) => $passengers->first()->reservation->setRelation('passengers', $passengers))
                ->sortBy(fn ($reservation) => $reservation->flight->departure_at)
                ->values();
        }

        return view('checkin.home', [
            'search' => $search,
            'reservations' => $reservations,
        ]);
    }

    /**
     * The check-in screen of one passenger. Opening it changes nothing: documents (C2),
     * seat (C3) and bags (C4) are added here before the agent confirms.
     */
    public function show(Request $request, Passenger $passenger, SeatService $seats, EntryRequirementChecker $entryCheck, JourneyService $journeys): View
    {
        $reservation = $passenger->reservation->loadMissing('segments.flight');
        $legNumbers = $journeys->legNumbers($reservation);
        $activeLeg = $request->integer('leg') ?: $journeys->legFor($passenger, $request->user());
        if (! in_array($activeLeg, $legNumbers, true)) {
            $activeLeg = $journeys->legFor($passenger, $request->user());
        }

        $direction = $journeys->segmentsOfLeg($reservation, $activeLeg)->first()?->direction
            ?? $journeys->directionFor($passenger, $request->user());
        $journeyFlights = $journeys->flightsOfLeg($reservation, $activeLeg);
        $flight = $journeyFlights->firstWhere('id', $request->integer('flight'))
            ?? $journeyFlights->first()
            ?? $passenger->reservation->flight;
        $coupon = $passenger->couponOn($flight);
        $legDestination = $journeys->finalDestinationOfLeg($reservation, $activeLeg);
        $legStatus = $journeys->statusOfLeg($passenger, $activeLeg);

        $legSummaries = collect($legNumbers)->map(function (int $legNo) use ($journeys, $reservation, $passenger) {
            $flights = $journeys->flightsOfLeg($reservation, $legNo);
            $first = $flights->first();
            $last = $flights->last();

            return [
                'leg' => $legNo,
                'origin' => $first?->origin,
                'destination' => $last?->destination,
                'status' => $journeys->statusOfLeg($passenger, $legNo),
                'first_flight' => $first,
                'checkin_opens_at' => $first?->checkin_opens_at,
                'window_open' => $first?->isCheckinWindowOpen() ?? false,
                'earlier_complete' => $journeys->earlierLegsAreComplete($passenger, $legNo),
            ];
        });

        $passenger->load([
            'reservation.flight',
            'reservation.segments.flight',
            'travelDocuments' => fn ($query) => $query->orderBy('id'),
            'travelDocuments.verifier',
            'accompanyingAdult',
            'coupon' => fn ($query) => $query->where('coupons.flight_id', $flight->id),
            'coupon.checkedInBy.user',
            'seatAssignment' => fn ($query) => $query->where('seat_assignments.flight_id', $flight->id),
            'seatAssignment.seat',
            'bags' => fn ($query) => $query
                ->where('direction', $direction)
                ->where('destination', $legDestination)
                ->orderBy('id'),
            'specialServices' => fn ($query) => $query->orderBy('id'),
            'activeBoardingPass' => fn ($query) => $query->where('boarding_passes.coupon_id', $coupon?->id),
        ]);
        $passenger->setRelation('checkedInBy', $passenger->coupon?->checkedInBy);

        // Drive the wizard off this leg's status (C23), not the first coupon of the whole trip.
        if ($legStatus === PassengerStatus::Booked) {
            $passenger->setRelation('coupon', $coupon);
        }

        $boardingPasses = app(BoardingPassService::class)->passesFor($passenger, $direction, $activeLeg)
            ->load(['coupon.flight']);

        return view('checkin.show', [
            'passenger' => $passenger,
            'flight' => $flight,
            'activeLeg' => $activeLeg,
            'legStatus' => $legStatus,
            'legSummaries' => $legSummaries,
            'hasMultipleLegs' => count($legNumbers) > 1,
            // Every flight of the active leg with the passenger's seat on it (a connection has several).
            'journeyFlights' => $journeyFlights,
            'seatsByFlight' => SeatAssignment::query()
                ->where('passenger_id', $passenger->id)
                ->whereIn('flight_id', $journeyFlights->modelKeys())
                ->with('seat')
                ->get()
                ->keyBy('flight_id'),
            'boardingPasses' => $boardingPasses,
            // ?edit=<document id> opens the edit form for that document.
            'editing' => $passenger->travelDocuments->firstWhere('id', $request->integer('edit')),
            // Advisory entry check (SR-49): read only. ?check_entry=1 shows the full result.
            'entryResult' => $entryCheck->check($passenger, $direction, $activeLeg),
            'showEntryCheck' => $request->boolean('check_entry'),
            // ?change_seat=1 opens the seat map for a checked-in passenger (C6).
            'changingSeat' => $request->boolean('change_seat'),
            // Same data as the reservation seat map (R1); infants have no seat.
            'seatMap' => $passenger->type === PassengerType::Infant
                ? collect()
                : $seats->seatMap($flight),
        ]);
    }

    /**
     * "Confirm check-in" (= complete check-in): StatusService checks every rule
     * (SR-6, SR-23, SR-25, SR-30, seat required) and the boarding pass is issued (C5).
     * Either way the agent stays on the check-in screen.
     */
    public function confirm(Request $request, Passenger $passenger, BoardingPassService $passes, JourneyService $journeys): RedirectResponse
    {
        // Kept in audit_log, whose columns hold 255 characters.
        $request->validate([
            'entry_reason' => ['nullable', 'string', 'max:255'],
            'leg' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);
        $leg = $request->integer('leg') ?: $journeys->legFor($passenger, $request->user());
        $screen = redirect()->route(
            'checkin.passengers.show',
            $journeys->hasMultipleLegs($passenger->reservation)
                ? ['passenger' => $passenger, 'leg' => $leg]
                : $passenger,
        );

        try {
            $passes->completeCheckIn($passenger, $request->user(), $request->input('entry_reason'), null, $leg);
        } catch (InvalidStatusChange|ExcessBaggageUnpaid $exception) {
            return $screen->with('error', $exception->getMessage());
        } catch (EntryReasonRequired $exception) {
            return $screen->withErrors(['entry_reason' => $exception->getMessage()]);
        }

        return $screen->with('success', $passenger->first_name.' '.$passenger->last_name.' checked in.');
    }

    /**
     * Set or clear passengers.email at the desk (CR-12). Station middleware applies.
     */
    public function updateEmail(UpdatePassengerEmailRequest $request, Passenger $passenger, PassengerService $passengers): RedirectResponse
    {
        $passengers->updateEmail($passenger, $request->validated('email'), $request->user());

        return back()->with('success', 'Email saved');
    }

    /**
     * Resend the check-in complete mail to the address currently used (CR-12).
     */
    public function resendEmail(
        Request $request,
        Passenger $passenger,
        BoardingPassService $passes,
        JourneyService $journeys,
    ): RedirectResponse {
        $leg = $request->integer('leg') ?: $journeys->legFor($passenger, $request->user());
        $screen = redirect()->route(
            'checkin.passengers.show',
            $journeys->hasMultipleLegs($passenger->reservation)
                ? ['passenger' => $passenger, 'leg' => $leg]
                : $passenger,
        );

        $sentTo = $passes->resendCheckInEmail($passenger, $request->user(), null, $leg);
        if ($sentTo === null) {
            return $screen->with('error', 'No email to send to.');
        }

        return $screen->with('success', 'Email resent to '.$sentTo);
    }
}
