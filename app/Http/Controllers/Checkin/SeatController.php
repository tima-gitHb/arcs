<?php

namespace App\Http\Controllers\Checkin;

use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Models\Seat;
use App\Services\SeatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Seat card on the check-in screen (C3, SR-7).
 */
class SeatController extends Controller
{
    public function assign(Request $request, Passenger $passenger, SeatService $seats): RedirectResponse
    {
        $request->validate(['seat_id' => ['required', 'integer', 'exists:seats,id']]);
        $seat = Seat::query()->findOrFail($request->integer('seat_id'));
        $screen = redirect()->route('checkin.passengers.show', $passenger);

        try {
            $seats->assign($passenger, $seat, $request->user());
        } catch (SeatTaken|SeatNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'Seat '.$seat->seat_no.' assigned.');
    }
}
