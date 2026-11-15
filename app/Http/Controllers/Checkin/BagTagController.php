<?php

namespace App\Http\Controllers\Checkin;

use App\Http\Controllers\Controller;
use App\Models\Bag;
use App\Models\Passenger;
use App\Services\BagTagService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF bag tags on the check-in screen (C15).
 */
class BagTagController extends Controller
{
    public function show(Bag $bag, BagTagService $tags): Response
    {
        return $tags->download($bag);
    }

    public function reprint(Request $request, Bag $bag, BagTagService $tags): Response
    {
        return $tags->reprint($bag, $request->user());
    }

    public function index(Passenger $passenger, BagTagService $tags): Response
    {
        abort_if($passenger->bags->isEmpty(), 404);

        return $tags->downloadAll($passenger);
    }
}
