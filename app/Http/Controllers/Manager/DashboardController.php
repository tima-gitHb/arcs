<?php

namespace App\Http\Controllers\Manager;

use App\Enums\PassengerStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Figures for each of today's flights (SR-47). Read only: nothing is changed here.
     * Always 3 queries, however many passengers there are: flights, passenger counts, bag counts.
     */
    public function index(Request $request): View
    {
        // Same definition of "today" as the <x-todays-flights> component (SR-41): today at the
        // Manager's station (UTC without one), flights from every station (B13a).
        $flights = Flight::departingToday($request->user())
            ->orderBy('departure_at')
            ->get();

        // [flight_id => [status => number of passengers]], counted by the database.
        $passengerCounts = DB::table('passengers')
            ->join('reservations', 'reservations.id', '=', 'passengers.reservation_id')
            ->whereIn('reservations.flight_id', $flights->modelKeys())
            ->select('reservations.flight_id', 'passengers.status', DB::raw('count(*) as total'))
            ->groupBy('reservations.flight_id', 'passengers.status')
            ->get()
            ->groupBy('flight_id')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        // [flight_id => number of bags]. A bag belongs to a passenger, who belongs to a reservation.
        $bagCounts = DB::table('bags')
            ->join('passengers', 'passengers.id', '=', 'bags.passenger_id')
            ->join('reservations', 'reservations.id', '=', 'passengers.reservation_id')
            ->whereIn('reservations.flight_id', $flights->modelKeys())
            ->select('reservations.flight_id', DB::raw('count(*) as total'))
            ->groupBy('reservations.flight_id')
            ->pluck('total', 'flight_id');

        $rows = $flights->map(function (Flight $flight) use ($passengerCounts, $bagCounts) {
            $count = fn (PassengerStatus $status) => (int) ($passengerCounts[$flight->id][$status->value] ?? 0);

            // Cancelled passengers can no longer check in, so they are left out of the total.
            // Boarded and NoShow passengers were checked in earlier, so they count as checked in.
            $checkedIn = $count(PassengerStatus::CheckedIn) + $count(PassengerStatus::Boarded) + $count(PassengerStatus::NoShow);
            $total = $checkedIn + $count(PassengerStatus::Booked);

            return [
                'flight' => $flight,
                'passengers' => $total,
                'checked_in' => $checkedIn,
                'percent' => $total > 0 ? (int) round($checkedIn / $total * 100) : 0,
                'boarded' => $count(PassengerStatus::Boarded),
                'no_shows' => $count(PassengerStatus::NoShow),
                'bags' => (int) ($bagCounts[$flight->id] ?? 0),
            ];
        });

        return view('manager.dashboard', ['rows' => $rows]);
    }
}
