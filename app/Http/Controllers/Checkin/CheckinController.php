<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\PassengerType;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\ExcessBaggageUnpaid;
use App\Exceptions\InvalidStatusChange;
use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Services\BoardingPassService;
use App\Services\EntryRequirementChecker;
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
                // Flights that left before today are not checked in any more.
                ->whereHas('reservation.flight', fn ($flight) => $flight->where('departure_at', '>=', today()))
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
    public function show(Request $request, Passenger $passenger, SeatService $seats, EntryRequirementChecker $entryCheck): View
    {
        $passenger->load([
            'reservation.flight',
            'travelDocuments' => fn ($query) => $query->orderBy('id'),
            'travelDocuments.verifier',
            'accompanyingAdult',
            'checkedInBy.user',
            'seatAssignment.seat',
            'bags' => fn ($query) => $query->orderBy('id'),
            'specialServices' => fn ($query) => $query->orderBy('id'),
            'activeBoardingPass',
        ]);

        return view('checkin.show', [
            'passenger' => $passenger,
            // ?edit=<document id> opens the edit form for that document.
            'editing' => $passenger->travelDocuments->firstWhere('id', $request->integer('edit')),
            // Advisory entry check (SR-49): read only. ?check_entry=1 shows the full result.
            'entryResult' => $entryCheck->check($passenger),
            'showEntryCheck' => $request->boolean('check_entry'),
            // ?change_seat=1 opens the seat map for a checked-in passenger (C6).
            'changingSeat' => $request->boolean('change_seat'),
            // Same data as the reservation seat map (R1); infants have no seat.
            'seatMap' => $passenger->type === PassengerType::Infant
                ? collect()
                : $seats->seatMap($passenger->reservation->flight),
        ]);
    }

    /**
     * "Confirm check-in" (= complete check-in): StatusService checks every rule
     * (SR-6, SR-23, SR-25, SR-30, seat required) and the boarding pass is issued (C5).
     * Either way the agent stays on the check-in screen.
     */
    public function confirm(Request $request, Passenger $passenger, BoardingPassService $passes): RedirectResponse
    {
        // Kept in audit_log, whose columns hold 255 characters.
        $request->validate(['entry_reason' => ['nullable', 'string', 'max:255']]);
        $screen = redirect()->route('checkin.passengers.show', $passenger);

        try {
            $passes->completeCheckIn($passenger, $request->user(), $request->input('entry_reason'));
        } catch (InvalidStatusChange|ExcessBaggageUnpaid $exception) {
            return $screen->with('error', $exception->getMessage());
        } catch (EntryReasonRequired $exception) {
            return $screen->withErrors(['entry_reason' => $exception->getMessage()]);
        }

        return $screen->with('success', $passenger->first_name.' '.$passenger->last_name.' checked in.');
    }
}
