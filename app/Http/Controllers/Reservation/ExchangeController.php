<?php

namespace App\Http\Controllers\Reservation;

use App\Exceptions\ExchangeNotAllowed;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\Reservation;
use App\Services\ExchangeService;
use App\Services\JourneyService;
use App\Services\Payments\CardDetails;
use App\Services\SeatService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Ticket exchange UI (Sprint 4 Feature 3): change one leg to another date/flight.
 */
class ExchangeController extends Controller
{
    public function form(Reservation $reservation, int $leg, JourneyService $journeys): View|RedirectResponse
    {
        $reservation->load(['passengers.coupons.flight', 'passengers.coupons.segment', 'segments.flight']);
        $legNumbers = $journeys->legNumbers($reservation);
        if (! in_array($leg, $legNumbers, true)) {
            return redirect()
                ->route('reservation.search', ['pnr' => $reservation->pnr])
                ->with('error', "Leg {$leg} is not on this booking.");
        }

        $flights = $journeys->flightsOfLeg($reservation, $leg);

        return view('reservation.exchange', [
            'reservation' => $reservation,
            'leg' => $leg,
            'legFlights' => $flights,
            'candidates' => collect(),
            'date' => null,
        ]);
    }

    public function search(Request $request, Reservation $reservation, int $leg, ExchangeService $exchanges, JourneyService $journeys): View|RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'passenger_ids' => ['required', 'array', 'min:1'],
            'passenger_ids.*' => ['integer'],
        ]);

        $reservation->load(['passengers.coupons.flight', 'passengers.coupons.segment', 'segments.flight']);
        $origin = $journeys->flightsOfLeg($reservation, $leg)->first()?->origin ?? 'UTC';
        $date = Carbon::parse($data['date'], Airport::timezoneOf($origin))->startOfDay();

        return view('reservation.exchange', [
            'reservation' => $reservation,
            'leg' => $leg,
            'legFlights' => $journeys->flightsOfLeg($reservation, $leg),
            'candidates' => $exchanges->candidateFlights($reservation, $leg, $date, $data['passenger_ids']),
            'date' => $data['date'],
            'passengerIds' => $data['passenger_ids'],
        ]);
    }

    public function review(Request $request, Reservation $reservation, int $leg, ExchangeService $exchanges, SeatService $seats): View|RedirectResponse
    {
        $data = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1'],
            'passenger_ids.*' => ['integer'],
            'flight_ids' => ['required', 'array', 'min:1'],
            'flight_ids.*' => ['integer'],
            'date' => ['nullable', 'date'],
        ]);

        try {
            $preview = $exchanges->preview(
                $reservation,
                $leg,
                $data['passenger_ids'],
                $data['flight_ids'],
            );
        } catch (ExchangeNotAllowed $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $seatMaps = [];
        foreach ($preview['new_flights'] as $flightRow) {
            $flight = Flight::query()->find($flightRow['id']);
            if ($flight) {
                $seatMaps[$flight->id] = $seats->seatMap($flight);
            }
        }

        return view('reservation.exchange-review', [
            'reservation' => $reservation->load(['passengers', 'segments.flight']),
            'leg' => $leg,
            'preview' => $preview,
            'passengerIds' => $data['passenger_ids'],
            'flightIds' => $data['flight_ids'],
            'date' => $data['date'] ?? null,
            'seatMaps' => $seatMaps,
        ]);
    }

    public function confirm(Request $request, Reservation $reservation, int $leg, ExchangeService $exchanges): RedirectResponse
    {
        $data = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1'],
            'passenger_ids.*' => ['integer'],
            'flight_ids' => ['required', 'array', 'min:1'],
            'flight_ids.*' => ['integer'],
            'seats' => ['nullable', 'array'],
            'card_number' => ['nullable', 'string'],
            'card_expiry' => ['nullable', 'string'],
            'card_cvc' => ['nullable', 'string'],
        ]);

        $card = null;
        if (! empty($data['card_number'])) {
            $card = new CardDetails(
                $data['card_number'],
                $data['card_expiry'] ?? '',
                $data['card_cvc'] ?? '',
            );
        }

        $seatMap = [];
        foreach ($data['seats'] ?? [] as $passengerId => $byFlight) {
            foreach ((array) $byFlight as $flightId => $seatId) {
                if ($seatId) {
                    $seatMap[(int) $passengerId][(int) $flightId] = (int) $seatId;
                }
            }
        }

        try {
            $result = $exchanges->confirm(
                $reservation,
                $request->user(),
                $leg,
                $data['passenger_ids'],
                $data['flight_ids'],
                $card,
                $seatMap,
            );
        } catch (ExchangeNotAllowed|InvalidStatusChange|PaymentDeclined|SeatTaken $exception) {
            return redirect()
                ->route('reservation.exchange', [$reservation, $leg])
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $payment = $result['payment'];
        $charge = $result['preview']['charge_cents'];
        $message = $charge > 0 && $payment
            ? 'Exchanged. Charged '.Money::format($charge).' to '.$payment->card_brand.' ••••'.$payment->card_last4
            : 'Exchanged. '.($result['preview']['difference_not_refunded'] ? 'The fare difference is not refunded. ' : '')
                .'Change fee '.Money::format($result['preview']['fee_cents']).'.';

        return redirect()
            ->route('reservation.search', ['pnr' => $reservation->pnr])
            ->with('success', $message)
            ->with('exchange_receipt', [
                'charge_cents' => $charge,
                'fee_cents' => $result['preview']['fee_cents'],
                'difference_cents' => $result['preview']['difference_cents'],
                'difference_not_refunded' => $result['preview']['difference_not_refunded'],
                'change_note' => $result['preview']['change_note'],
                'card_brand' => $payment?->card_brand,
                'card_last4' => $payment?->card_last4,
            ]);
    }
}
