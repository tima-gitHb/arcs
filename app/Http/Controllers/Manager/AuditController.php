<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    /**
     * Audit log (SR-26, SR-45): every status change and correction, newest first.
     * Read only. Filters arrive as GET parameters: flight_id, passenger, user_id, date.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'flight_id' => ['nullable', 'integer'],
            'passenger' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entries = AuditEntry::query()
            // Eager loading: a few extra queries for the whole page instead of several per row.
            ->with(['passenger.reservation.flight', 'flight', 'user'])
            // A row is about a flight through its passenger, or directly (flight_id).
            ->when($filters['flight_id'] ?? null, fn ($query, $flightId) => $query->where(fn ($entry) => $entry
                ->where('flight_id', $flightId)
                ->orWhereHas('passenger.reservation', fn ($reservation) => $reservation->where('flight_id', $flightId))))
            ->when($filters['passenger'] ?? null, fn ($query, $text) => $query
                ->whereHas('passenger', fn ($passenger) => $passenger
                    ->where('first_name', 'like', "%{$text}%")
                    ->orWhere('last_name', 'like', "%{$text}%")
                    ->orWhereHas('reservation', fn ($reservation) => $reservation->where('pnr', 'like', "%{$text}%"))))
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('changed_at', $date))
            // Same second? Highest id (the latest written) first, so the order is stable.
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('manager.audit', [
            'entries' => $entries,
            'flights' => Flight::orderByDesc('departure_at')->get(),
            'staff' => User::orderBy('name')->get(),
        ]);
    }
}
