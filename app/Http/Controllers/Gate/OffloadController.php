<?php

namespace App\Http\Controllers\Gate;

use App\Enums\PassengerStatus;
use App\Exceptions\BagNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Services\BagService;
use App\Services\JourneyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Confirm offloaded" on the Bags to offload list (G4, SR-28).
 */
class OffloadController extends Controller
{
    public function confirm(Request $request, Bag $bag, BagService $bags): RedirectResponse
    {
        // Back to the manifest of the flight the passenger missed, in the bag's direction (B13b).
        $flightId = app(JourneyService::class)->couponsOf($bag->passenger, $bag->direction)
            ->firstWhere('status', PassengerStatus::NoShow)?->flight_id ?? $bag->passenger->reservation->flight_id;
        $manifest = redirect()->route('gate.flights.show', $flightId);

        try {
            $bags->confirmOffload($bag, $request->user());
        } catch (BagNotAllowed $exception) {
            return $manifest->with('error', $exception->getMessage());
        }

        return $manifest->with('success', "Bag {$bag->tag_no} offloaded.");
    }
}
