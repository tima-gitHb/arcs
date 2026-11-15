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
 * "Today" is today at the viewer's station (B13a). Check-in, gate and reservation staff see
 * their station's departures (reservation staff with no station see all); the Manager sees all.
 * Departure times are shown in the origin airport's local time.
 */
class TodaysFlights extends Component
{
    public Role $role;

    /** @var Collection<int, Flight> */
    public Collection $flights;

    public function __construct(Role|string $role)
    {
        $this->role = $role instanceof Role ? $role : Role::from($role);
        $user = auth()->user();
        // The Manager sees every station; the others their own (none set: every station).
        $station = $this->role === Role::Manager ? null : $user?->airport_code;

        $this->flights = Flight::query()
            ->when($user, fn ($query) => $query->departingToday($user), fn ($query) => $query->whereBetween('departure_at', [now()->startOfDay(), now()->endOfDay()]))
            ->when($station, fn ($query, string $code) => $query->departingFrom($code))
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
            Role::Checkin => ['label' => 'Check-in list', 'url' => route('checkin.flights.show', $flight)],
            default => null,
        };
    }

    public function hasActions(): bool
    {
        return in_array($this->role, [Role::Gate, Role::Checkin], true);
    }

    public function render(): View
    {
        return view('components.todays-flights');
    }
}
