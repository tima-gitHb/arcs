<?php

namespace App\View\Components;

use App\Enums\Role;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Today's flights, sorted by departure (SR-41). Shared by the role home pages:
 *     <x-todays-flights :role="auth()->user()->role" />
 * The role is passed on to the view so each module can add its own action per row later.
 */
class TodaysFlights extends Component
{
    public Role $role;

    /** @var Collection<int, Flight> */
    public Collection $flights;

    public function __construct(Role|string $role)
    {
        $this->role = $role instanceof Role ? $role : Role::from($role);

        $this->flights = Flight::whereBetween('departure_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('departure_at')
            ->get();
    }

    /**
     * The action a role gets on each row, or null. Modules add theirs here.
     *
     * @return array{label: string, url: string}|null
     */
    public function actionFor(Flight $flight): ?array
    {
        return match ($this->role) {
            Role::Gate => ['label' => 'Manifest', 'url' => route('gate.flights.show', $flight)],
            default => null,
        };
    }

    public function hasActions(): bool
    {
        return $this->role === Role::Gate;
    }

    public function render(): View
    {
        return view('components.todays-flights');
    }
}
