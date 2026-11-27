<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\BagType;
use App\Exceptions\BagNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkin\StoreBagRequest;
use App\Models\Bag;
use App\Models\Passenger;
use App\Services\BagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Bags card on the check-in screen (C4).
 */
class BagController extends Controller
{
    public function store(StoreBagRequest $request, Passenger $passenger, BagService $bags): RedirectResponse
    {
        $leg = $request->integer('leg') ?: null;
        $screen = redirect()->route('checkin.passengers.show', array_filter([
            'passenger' => $passenger,
            'leg' => $leg,
        ]));

        try {
            $bag = $bags->add(
                $passenger,
                (float) $request->validated('weight_kg'),
                BagType::from($request->validated('type')),
                $request->user(),
                null,
                $leg,
            );
        } catch (BagNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'Bag '.$bag->tag_no.' added.');
    }

    public function destroy(Request $request, Bag $bag, BagService $bags): RedirectResponse
    {
        $leg = $request->integer('leg') ?: null;
        $screen = redirect()->route('checkin.passengers.show', array_filter([
            'passenger' => $bag->passenger_id,
            'leg' => $leg,
        ]));

        try {
            $bags->remove($bag, $request->user());
        } catch (BagNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', 'Bag '.$bag->tag_no.' removed.');
    }
}
