<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\CabinClass;
use App\Enums\DocumentType;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\EntryReasonRequired;
use App\Exceptions\ExcessBaggageUnpaid;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\Setting;
use App\Services\BagService;
use App\Services\BoardingPassService;
use App\Services\EntryRequirementChecker;
use App\Services\JourneyService;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Group check-in for every passenger on one PNR (C12 / SR-61).
 */
class GroupCheckInController extends Controller
{
    public function show(
        Reservation $reservation,
        SeatService $seats,
        EntryRequirementChecker $entryCheck,
        BagService $bags,
    ): View|RedirectResponse {
        if ($refused = $this->refuseConnection($reservation)) {
            return $refused;
        }

        $reservation->load([
            'flight',
            'passengers.seatAssignment.seat',
            'passengers.travelDocuments',
            'passengers.bags',
            'passengers.activeBoardingPass',
            'passengers.accompanyingAdult',
        ]);

        $flight = $reservation->flight;
        $minMonths = (int) (Setting::find('passport_min_months')?->value ?? 0);
        $validUntil = $flight->departureDate()->addMonthsNoOverflow($minMonths);

        $rows = $reservation->passengers->map(function (Passenger $passenger) use ($entryCheck, $bags, $validUntil) {
            $docs = $passenger->travelDocuments->whereIn('type', [DocumentType::Passport, DocumentType::IdCard]);
            if ($docs->isEmpty()) {
                $documentStatus = 'No travel document';
            } elseif (! $docs->contains(fn ($doc) => $doc->expiry_date->gte($validUntil))) {
                $documentStatus = 'Document expired';
            } else {
                $documentStatus = 'OK';
            }

            $excess = $bags->excess($passenger);

            return [
                'passenger' => $passenger,
                'documentStatus' => $documentStatus,
                'entryResult' => $entryCheck->check($passenger),
                'excess' => $excess,
                'excessDueCents' => $bags->excessDueCents($passenger),
            ];
        });

        $booked = $reservation->passengers->where('status', PassengerStatus::Booked);
        $hasPasses = $reservation->passengers->contains(fn (Passenger $p) => $p->activeBoardingPass !== null);

        return view('checkin.group', [
            'reservation' => $reservation,
            'flight' => $flight,
            'rows' => $rows,
            'seatMap' => $seats->seatMap($flight),
            'canConfirm' => $booked->isNotEmpty(),
            'hasPasses' => $hasPasses,
            'groupResults' => session('group_results'),
        ]);
    }

    public function assignSeats(Request $request, Reservation $reservation, SeatService $seats): RedirectResponse
    {
        if ($refused = $this->refuseConnection($reservation)) {
            return $refused;
        }

        $reservation->load(['flight', 'passengers']);
        $screen = redirect()->route('checkin.reservations.group', $reservation);

        $data = $request->validate([
            'seats' => ['required', 'array'],
            'seats.*' => ['nullable', 'integer', 'exists:seats,id'],
        ]);

        $seatIds = array_filter($data['seats'], fn ($id) => $id !== null && $id !== '');
        if (count($seatIds) !== count(array_unique($seatIds))) {
            return $screen->withErrors(['seats' => 'Each seat can only be assigned to one passenger.']);
        }

        $passengers = $reservation->passengers->keyBy('id');

        try {
            DB::transaction(function () use ($seatIds, $passengers, $seats, $request, $reservation) {
                foreach ($seatIds as $passengerId => $seatId) {
                    $passenger = $passengers->get((int) $passengerId);
                    if ($passenger === null || $passenger->reservation_id !== $reservation->id) {
                        throw new SeatNotAllowed('That passenger is not on this reservation.');
                    }
                    if ($passenger->type === PassengerType::Infant) {
                        throw new SeatNotAllowed('An infant has no seat.');
                    }
                    if ($passenger->status !== PassengerStatus::Booked) {
                        throw new SeatNotAllowed('Seats can only be assigned for booked passengers.');
                    }

                    $seat = Seat::query()->findOrFail($seatId);
                    $bookedCabin = $passenger->cabin_class ?? CabinClass::Economy;
                    if ($seat->cabin_class !== $bookedCabin) {
                        throw new SeatNotAllowed('Seat must be in the booked cabin class ('.$bookedCabin->label().').');
                    }

                    $seats->assign($passenger, $seat, $request->user());
                }
            });
        } catch (SeatNotAllowed|SeatTaken $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'Seats saved for the group.');
    }

    public function confirmAll(Request $request, Reservation $reservation, BoardingPassService $passes): RedirectResponse
    {
        if ($refused = $this->refuseConnection($reservation)) {
            return $refused;
        }

        $reservation->load(['passengers']);
        $request->validate([
            'entry_reasons' => ['nullable', 'array'],
            'entry_reasons.*' => ['nullable', 'string', 'max:255'],
        ]);

        $reasons = $request->input('entry_reasons', []);
        $results = [];

        foreach ($reservation->passengers->where('status', PassengerStatus::Booked) as $passenger) {
            $reason = $reasons[$passenger->id] ?? null;

            try {
                $passes->completeCheckIn($passenger, $request->user(), $reason);
                $results[] = [
                    'passenger_id' => $passenger->id,
                    'name' => $passenger->first_name.' '.$passenger->last_name,
                    'ok' => true,
                    'message' => 'Checked in',
                ];
            } catch (InvalidStatusChange|ExcessBaggageUnpaid|EntryReasonRequired $exception) {
                $results[] = [
                    'passenger_id' => $passenger->id,
                    'name' => $passenger->first_name.' '.$passenger->last_name,
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return redirect()
            ->route('checkin.reservations.group', $reservation)
            ->with('group_results', $results);
    }

    public function boardingPasses(Reservation $reservation, BoardingPassService $passes): View
    {
        $reservation->load([
            'flight',
            'passengers.seatAssignment.seat',
            'passengers.activeBoardingPass',
            'passengers.accompanyingAdult',
            'passengers.reservation',
        ]);

        $cards = $reservation->passengers
            ->filter(fn (Passenger $passenger) => $passenger->activeBoardingPass !== null)
            ->map(fn (Passenger $passenger) => $passes->printData($passenger, $passenger->activeBoardingPass))
            ->values();

        abort_if($cards->isEmpty(), 404);

        return view('checkin.boarding-passes', [
            'reservation' => $reservation,
            'cards' => $cards,
        ]);
    }

    /**
     * Group check-in handles one flight per passenger: a reservation with a connection is checked in
     * passenger by passenger (B13b). Null when the group screen can be used.
     */
    private function refuseConnection(Reservation $reservation): ?RedirectResponse
    {
        if (! app(JourneyService::class)->hasConnection($reservation)) {
            return null;
        }

        return redirect()->route('checkin.home', ['q' => $reservation->pnr])
            ->with('error', 'Connecting passenger: use single check-in');
    }
}
