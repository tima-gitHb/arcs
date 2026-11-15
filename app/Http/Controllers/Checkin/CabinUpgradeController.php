<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\CabinClass;
use App\Enums\PaymentStatus;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Exceptions\UpgradeNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Seat;
use App\Services\CabinUpgradeService;
use App\Services\JourneyService;
use App\Services\Payments\CardDetails;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Paid cabin upgrade at check-in (C14: per flight on a connection).
 */
class CabinUpgradeController extends Controller
{
    public function create(
        Request $request,
        Passenger $passenger,
        CabinUpgradeService $upgrades,
        SeatService $seats,
        JourneyService $journeys,
    ): View|RedirectResponse {
        $direction = $journeys->directionFor($passenger, $request->user());
        $journeyFlights = $journeys->flightsOf($passenger->reservation, $direction);
        $flight = $journeyFlights->firstWhere('id', $request->integer('flight'))
            ?? $journeyFlights->first()
            ?? $passenger->reservation->flight;

        $passenger->load([
            'reservation.flight',
            'seatAssignment' => fn ($query) => $query->where('seat_assignments.flight_id', $flight->id),
            'seatAssignment.seat',
        ]);
        $from = $passenger->cabin_class ?? CabinClass::Economy;
        $options = $upgrades->higherClasses($from);

        try {
            if ($options === []) {
                throw new UpgradeNotAllowed('Already in the highest cabin class.');
            }
            $upgrades->priceCents($passenger, $options[0], $flight);
        } catch (UpgradeNotAllowed $exception) {
            return redirect()->route('checkin.passengers.show', $this->showParams($passenger, $flight, $journeyFlights))
                ->with('error', $exception->getMessage());
        }

        $prices = [];
        foreach ($options as $cabin) {
            $prices[$cabin->value] = $upgrades->priceCents($passenger, $cabin, $flight);
        }

        return view('checkin.upgrade', [
            'passenger' => $passenger,
            'flight' => $flight,
            'journeyFlights' => $journeyFlights,
            'from' => $from,
            'options' => $options,
            'prices' => $prices,
            'seatMap' => $seats->seatMap($flight),
        ]);
    }

    public function store(Request $request, Passenger $passenger, CabinUpgradeService $upgrades, JourneyService $journeys): RedirectResponse
    {
        $safeInput = fn () => $request->except(['card_number', 'card_cvc', 'card_expiry', 'card_name']);

        $data = $request->validate([
            'cabin_class' => ['required', 'string'],
            'seat_id' => ['required', 'integer', 'exists:seats,id'],
            'flight_id' => ['nullable', 'integer', 'exists:flights,id'],
            'card_number' => ['required', 'string', 'max:19'],
            'card_expiry' => ['required', 'string', 'max:5'],
            'card_cvc' => ['required', 'string', 'max:4'],
            'card_name' => ['nullable', 'string', 'max:80'],
        ]);

        $direction = $journeys->directionFor($passenger, $request->user());
        $journeyFlights = $journeys->flightsOf($passenger->reservation, $direction);
        $flight = $journeyFlights->firstWhere('id', (int) ($data['flight_id'] ?? 0))
            ?? $journeyFlights->first()
            ?? $passenger->reservation->flight;

        $screen = redirect()->route('checkin.passengers.show', $this->showParams($passenger, $flight, $journeyFlights));
        $form = redirect()->route('checkin.passengers.upgrade', $this->upgradeParams($passenger, $flight, $journeyFlights));

        try {
            $to = CabinClass::from($data['cabin_class']);
            $seat = Seat::query()->findOrFail($data['seat_id']);
            $payment = $upgrades->upgrade(
                $passenger,
                $to,
                $seat,
                new CardDetails($data['card_number'], $data['card_expiry'], $data['card_cvc']),
                $request->user(),
                $flight,
            );
        } catch (\ValueError) {
            return $form->withInput($safeInput())->with('error', 'Unknown cabin class.');
        } catch (UpgradeNotAllowed|SeatTaken|SeatNotAllowed $exception) {
            return $form->withInput($safeInput())->with('error', $exception->getMessage());
        }

        if ($payment->status !== PaymentStatus::Succeeded) {
            return $form->withInput($safeInput())->with('error', 'Card declined');
        }

        $label = $passenger->fresh()->cabin_class->label();
        $message = $journeyFlights->count() > 1
            ? $passenger->fresh()->first_name.' upgraded to '.$label.' on '.$flight->flight_no.'.'
            : $passenger->fresh()->first_name.' upgraded to '.$label.'.';

        return $screen->with('success', $message);
    }

    /**
     * @param  Collection<int, Flight>  $journeyFlights
     * @return array{0: Passenger}|array{0: Passenger, flight: int}
     */
    private function showParams(Passenger $passenger, Flight $flight, $journeyFlights): array
    {
        return $journeyFlights->count() > 1
            ? [$passenger, 'flight' => $flight->id]
            : [$passenger];
    }

    /**
     * @param  Collection<int, Flight>  $journeyFlights
     * @return array{0: Passenger}|array{0: Passenger, flight: int}
     */
    private function upgradeParams(Passenger $passenger, Flight $flight, $journeyFlights): array
    {
        return $journeyFlights->count() > 1
            ? [$passenger, 'flight' => $flight->id]
            : [$passenger];
    }
}
