<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\CabinClass;
use App\Enums\PaymentStatus;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Exceptions\UpgradeNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Models\Seat;
use App\Services\CabinUpgradeService;
use App\Services\Payments\CardDetails;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Paid cabin upgrade at check-in.
 */
class CabinUpgradeController extends Controller
{
    public function create(Passenger $passenger, CabinUpgradeService $upgrades, SeatService $seats): View|RedirectResponse
    {
        $passenger->load(['reservation.flight', 'seatAssignment.seat']);
        $from = $passenger->cabin_class ?? CabinClass::Economy;
        $options = $upgrades->higherClasses($from);

        try {
            if ($options === []) {
                throw new UpgradeNotAllowed('Already in the highest cabin class.');
            }
            // Probe eligibility with the first higher class.
            $upgrades->priceCents($passenger, $options[0]);
        } catch (UpgradeNotAllowed $exception) {
            return redirect()->route('checkin.passengers.show', $passenger)
                ->with('error', $exception->getMessage());
        }

        $prices = [];
        foreach ($options as $cabin) {
            $prices[$cabin->value] = $upgrades->priceCents($passenger, $cabin);
        }

        return view('checkin.upgrade', [
            'passenger' => $passenger,
            'flight' => $passenger->reservation->flight,
            'from' => $from,
            'options' => $options,
            'prices' => $prices,
            'seatMap' => $seats->seatMap($passenger->reservation->flight),
        ]);
    }

    public function store(Request $request, Passenger $passenger, CabinUpgradeService $upgrades): RedirectResponse
    {
        $screen = redirect()->route('checkin.passengers.show', $passenger);
        $form = redirect()->route('checkin.passengers.upgrade', $passenger);
        $safeInput = fn () => $request->except(['card_number', 'card_cvc', 'card_expiry', 'card_name']);

        $data = $request->validate([
            'cabin_class' => ['required', 'string'],
            'seat_id' => ['required', 'integer', 'exists:seats,id'],
            'card_number' => ['required', 'string', 'max:19'],
            'card_expiry' => ['required', 'string', 'max:5'],
            'card_cvc' => ['required', 'string', 'max:4'],
            'card_name' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $to = CabinClass::from($data['cabin_class']);
            $seat = Seat::query()->findOrFail($data['seat_id']);
            $payment = $upgrades->upgrade(
                $passenger,
                $to,
                $seat,
                new CardDetails($data['card_number'], $data['card_expiry'], $data['card_cvc']),
                $request->user(),
            );
        } catch (\ValueError) {
            return $form->withInput($safeInput())->with('error', 'Unknown cabin class.');
        } catch (UpgradeNotAllowed|SeatTaken|SeatNotAllowed $exception) {
            return $form->withInput($safeInput())->with('error', $exception->getMessage());
        }

        if ($payment->status !== PaymentStatus::Succeeded) {
            return $form->withInput($safeInput())->with('error', 'Card declined');
        }

        return $screen->with('success', $passenger->fresh()->first_name.' upgraded to '.$passenger->fresh()->cabin_class->label().'.');
    }
}
