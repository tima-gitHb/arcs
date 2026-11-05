<?php

namespace App\Http\Controllers\Gate;

use App\Enums\PassengerStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\BoardingPassService;
use Illuminate\View\View;

/**
 * Read-only printable boarding pass at the gate, e.g. after a late check-in (G5, SR-29).
 * Same page as check-in; reprinting stays with check-in staff.
 */
class BoardingPassController extends Controller
{
    public function show(Flight $flight, Passenger $passenger, BoardingPassService $passes): View
    {
        $pass = $passenger->activeBoardingPass;

        abort_if(
            $pass === null
            || $passenger->reservation->flight_id !== $flight->id
            || ! in_array($passenger->status, [PassengerStatus::CheckedIn, PassengerStatus::Boarded], true),
            404,
        );

        return view('checkin.boarding-pass', $passes->printData($passenger, $pass));
    }
}
