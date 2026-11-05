<?php

namespace App\Http\Controllers\Gate;

use App\Exceptions\BagNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Services\BagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Confirm offloaded" on the Bags to offload list (G4, SR-28).
 */
class OffloadController extends Controller
{
    public function confirm(Request $request, Bag $bag, BagService $bags): RedirectResponse
    {
        $manifest = redirect()->route('gate.flights.show', $bag->passenger->reservation->flight_id);

        try {
            $bags->confirmOffload($bag, $request->user());
        } catch (BagNotAllowed $exception) {
            return $manifest->with('error', $exception->getMessage());
        }

        return $manifest->with('success', "Bag {$bag->tag_no} offloaded.");
    }
}
