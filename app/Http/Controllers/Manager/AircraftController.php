<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreAircraftRequest;
use App\Services\AircraftService;
use Illuminate\Http\RedirectResponse;

class AircraftController extends Controller
{
    public function store(StoreAircraftRequest $request, AircraftService $aircraftService): RedirectResponse
    {
        $aircraft = $aircraftService->create($request->validated());

        // "Business 30, Premium 18, Economy 132": the seat count of each class, in cabin order.
        $perClass = collect(CabinClass::cases())
            ->map(fn (CabinClass $cabin) => $cabin->label().' '.$aircraft->seats()->where('cabin_class', $cabin)->count())
            ->implode(', ');

        return redirect()->route('manager.flights')
            ->with('success', "Aircraft {$aircraft->registration} created with {$aircraft->seats()->count()} seats ({$perClass}).");
    }
}
