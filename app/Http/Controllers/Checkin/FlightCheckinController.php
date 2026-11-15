<?php

namespace App\Http\Controllers\Checkin;

use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Services\CheckinFlightService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Check-in by flight (C13): today's flights open this list of reservations.
 */
class FlightCheckinController extends Controller
{
    public function show(Request $request, Flight $flight, CheckinFlightService $flights): View
    {
        $filter = (string) $request->query('q', '');

        return view('checkin.flight', [
            'flight' => $flight,
            'filter' => $filter,
            'rows' => $flights->reservations($flight, $filter),
        ]);
    }
}
