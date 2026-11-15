<?php

namespace App\Http\Controllers\Checkin;

use App\Exceptions\BoardingPassNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Printable boarding pass and reprint (C5 / C14: one pass per flight).
 */
class BoardingPassController extends Controller
{
    public function show(Request $request, Passenger $passenger, BoardingPassService $passes, JourneyService $journeys): View
    {
        $flight = $this->pickFlight($request, $this->flightsFor($request, $passenger, $journeys), $passenger);
        $pass = $passenger->couponOn($flight)?->boardingPasses()->whereNull('voided_at')->first()
            ?? $passenger->activeBoardingPass;
        abort_if($pass === null, 404);

        return view('checkin.boarding-pass', $passes->printData($passenger, $pass));
    }

    /**
     * Reprint: the old pass on that flight is voided and a new one issued (SR-32).
     */
    public function reprint(Request $request, Passenger $passenger, BoardingPassService $passes, JourneyService $journeys): RedirectResponse
    {
        $flights = $this->flightsFor($request, $passenger, $journeys);
        $flight = $this->pickFlight($request, $flights, $passenger);
        $screen = redirect()->route(
            'checkin.passengers.show',
            $flights->count() > 1 ? [$passenger, 'flight' => $flight->id] : [$passenger],
        );

        try {
            $passes->issue($passenger, $request->user(), $flight);
        } catch (BoardingPassNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        $message = $flights->count() > 1
            ? 'New boarding pass issued for '.$flight->flight_no.'; the old one is void.'
            : 'New boarding pass issued; the old one is void.';

        return $screen->with('success', $message);
    }

    /**
     * @return Collection<int, Flight>
     */
    private function flightsFor(Request $request, Passenger $passenger, JourneyService $journeys)
    {
        $direction = $journeys->directionFor($passenger, $request->user());

        return $journeys->flightsOf($passenger->reservation, $direction);
    }

    /**
     * @param  Collection<int, Flight>  $flights
     */
    private function pickFlight(Request $request, $flights, Passenger $passenger): Flight
    {
        $flightId = $request->integer('flight_id') ?: $request->integer('flight');

        return $flights->firstWhere('id', $flightId)
            ?? $flights->first()
            ?? $passenger->reservation->flight;
    }
}
