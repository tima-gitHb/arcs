<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreAircraftRequest;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Services\AircraftService;
use App\Support\Limits;
use App\Support\ManagerStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AircraftController extends Controller
{
    public function index(): View
    {
        ManagerStation::apply(request());

        $fleet = Aircraft::withCount('seats')
            ->orderBy('registration')
            ->get()
            ->map(function (Aircraft $aircraft) {
                $next = Flight::query()
                    ->where('aircraft_id', $aircraft->id)
                    ->where('departure_at', '>=', now())
                    ->orderBy('departure_at')
                    ->first();

                $perCabin = collect(CabinClass::cases())->mapWithKeys(
                    fn (CabinClass $cabin) => [$cabin->value => $aircraft->seats()->where('cabin_class', $cabin)->count()]
                );

                return [
                    'aircraft' => $aircraft,
                    'next' => $next,
                    'cabins' => $perCabin,
                    'in_use' => $next !== null,
                ];
            });

        return view('manager.aircraft', ['fleet' => $fleet]);
    }

    public function create(): View
    {
        ManagerStation::apply(request());

        return view('manager.aircraft-create', [
            'maxSeats' => Limits::maxAircraftSeats(),
        ]);
    }

    public function store(StoreAircraftRequest $request, AircraftService $aircraftService): RedirectResponse
    {
        $aircraft = $aircraftService->create($request->validated());

        // "Business 30, Premium 18, Economy 132": the seat count of each class, in cabin order.
        $perClass = collect(CabinClass::cases())
            ->map(fn (CabinClass $cabin) => $cabin->label().' '.$aircraft->seats()->where('cabin_class', $cabin)->count())
            ->implode(', ');

        return redirect()->route('manager.aircraft.index')
            ->with('success', "Aircraft {$aircraft->registration} created with {$aircraft->seats()->count()} seats ({$perClass}).");
    }
}
