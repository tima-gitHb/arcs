<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreAircraftRequest;
use App\Services\AircraftService;
use Illuminate\Http\RedirectResponse;

class AircraftController extends Controller
{
    public function store(StoreAircraftRequest $request, AircraftService $aircraftService): RedirectResponse
    {
        $aircraft = $aircraftService->create($request->validated());

        return redirect()->route('manager.flights')
            ->with('success', "Aircraft {$aircraft->registration} created with {$aircraft->seats()->count()} seats.");
    }
}
