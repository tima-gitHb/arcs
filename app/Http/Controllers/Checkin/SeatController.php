<?php

namespace App\Http\Controllers\Checkin;

use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Seat;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Seat card on the check-in screen (C3: SR-7, C6: SR-20).
 */
class SeatController extends Controller
{
    public function assign(Request $request, Passenger $passenger, SeatService $seats): RedirectResponse
    {
        $request->validate([
            'seat_id' => ['required', 'integer', 'exists:seats,id'],
            // Which flight of a connection the seat is for (B13b); none = the first flight.
            'flight_id' => ['nullable', 'integer', 'exists:flights,id'],
        ]);
        $seat = Seat::query()->findOrFail($request->integer('seat_id'));
        $flight = $request->filled('flight_id') ? Flight::query()->findOrFail($request->integer('flight_id')) : null;
        $screen = redirect()->route('checkin.passengers.show', array_filter([$passenger, 'flight' => $flight?->id]));

        try {
            $seats->assign($passenger, $seat, $request->user(), $flight);
        } catch (SeatTaken|SeatNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'Seat '.$seat->seat_no.' assigned.');
    }

    /**
     * "Change seat" for a checked-in passenger (C6, SR-20): the boarding pass is replaced too.
     */
    public function change(Request $request, Passenger $passenger, SeatService $seats): RedirectResponse
    {
        $request->validate([
            'seat_id' => ['required', 'integer', 'exists:seats,id'],
            // Which flight of a connection the seat is for (B13b); none = the first flight.
            'flight_id' => ['nullable', 'integer', 'exists:flights,id'],
        ]);
        $seat = Seat::query()->findOrFail($request->integer('seat_id'));
        $flight = $request->filled('flight_id') ? Flight::query()->findOrFail($request->integer('flight_id')) : null;
        $screen = redirect()->route('checkin.passengers.show', array_filter([$passenger, 'flight' => $flight?->id]));

        try {
            $seats->change($passenger, $seat, $request->user(), $flight);
        } catch (SeatTaken|SeatNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'Seat changed, new boarding pass ready');
    }
}
