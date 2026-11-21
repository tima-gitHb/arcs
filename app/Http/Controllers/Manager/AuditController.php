<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\User;
use App\Support\ManagerStation;
use Illuminate\Database\Eloquent\Builder;
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

        $station = ManagerStation::apply($request);

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
            // A day at the Manager's station (UTC without one), B13a.
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereBetween('changed_at', $request->user()->stationDay($date)))
            ->when($station, fn ($query) => $query->where(fn ($entry) => $this->fromStation($entry, $station)))
            // Same second? Highest id (the latest written) first, so the order is stable.
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $selectedId = $request->query('entry');
        $selected = null;
        if (is_numeric($selectedId)) {
            $selected = AuditEntry::with(['passenger.reservation.flight', 'flight', 'user'])
                ->find((int) $selectedId);
        }

        return view('manager.audit', [
            'entries' => $entries,
            'flights' => Flight::query()
                ->when($station, fn ($query) => $query->departingFrom($station))
                ->orderByDesc('departure_at')
                ->get(),
            'staff' => User::orderBy('name')->get(),
            'station' => $station,
            'selected' => $selected,
        ]);
    }

    /**
     * A row belongs to this station through its flight, or through the passenger's reservation
     * flight when it has no flight of its own. An airport row matches "airport {CODE} ".
     * Fare, fee and any other row with neither id do not match.
     *
     * @param  Builder<AuditEntry>  $entry
     */
    private function fromStation($entry, string $station): void
    {
        $entry->whereHas('flight', fn ($flight) => $flight->where('origin', $station))
            ->orWhere(fn ($row) => $row
                ->whereNull('flight_id')
                ->whereHas('passenger.reservation.flight', fn ($flight) => $flight->where('origin', $station)))
            ->orWhere(fn ($row) => $row
                ->whereNull('flight_id')
                ->whereNull('passenger_id')
                ->where('field', 'like', "airport {$station} %"));
    }
}
