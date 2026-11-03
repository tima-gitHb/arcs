<?php

namespace App\Http\Controllers\Checkin;

use App\Exceptions\BoardingPassNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Services\BoardingPassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable boarding pass and reprint (C5).
 */
class BoardingPassController extends Controller
{
    public function show(Passenger $passenger, BoardingPassService $passes): View
    {
        $pass = $passenger->activeBoardingPass;
        abort_if($pass === null, 404);

        $passenger->load(['reservation.flight', 'seatAssignment.seat', 'accompanyingAdult']);
        $flight = $passenger->reservation->flight;

        return view('checkin.boarding-pass', [
            'passenger' => $passenger,
            'pass' => $pass,
            'flight' => $flight,
            'boardingTime' => $passes->boardingTime($flight),
            'barcodeSvg' => $passes->barcodeSvg($pass),
        ]);
    }

    /**
     * Reprint: the old pass is voided and a new one issued (SR-32), e.g. after a gate change (SR-42).
     */
    public function reprint(Request $request, Passenger $passenger, BoardingPassService $passes): RedirectResponse
    {
        $screen = redirect()->route('checkin.passengers.show', $passenger);

        try {
            $passes->issue($passenger, $request->user());
        } catch (BoardingPassNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'New boarding pass issued; the old one is void.');
    }
}
