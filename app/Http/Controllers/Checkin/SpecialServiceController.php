<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\SpecialServiceCode;
use App\Exceptions\SpecialServiceNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkin\StoreSpecialServiceRequest;
use App\Models\Passenger;
use App\Models\SpecialService;
use App\Services\SpecialServiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Special services panel on the check-in screen (C7: SR-2, SR-44).
 */
class SpecialServiceController extends Controller
{
    public function store(StoreSpecialServiceRequest $request, Passenger $passenger, SpecialServiceService $services): RedirectResponse
    {
        $code = SpecialServiceCode::from($request->validated('code'));
        $screen = redirect()->route('checkin.passengers.show', $passenger);

        try {
            $services->add($passenger, $code, $request->validated('details'), $request->user());
        } catch (SpecialServiceNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', $code->value.' added.');
    }

    public function destroy(Request $request, SpecialService $service, SpecialServiceService $services): RedirectResponse
    {
        $screen = redirect()->route('checkin.passengers.show', $service->passenger_id);

        try {
            $services->remove($service, $request->user());
        } catch (SpecialServiceNotAllowed $exception) {
            return $screen->with('error', $exception->getMessage());
        }

        return $screen->with('success', $service->type->value.' removed.');
    }
}
