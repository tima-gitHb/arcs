<?php

namespace App\Http\Controllers\Manager;

use App\Enums\PassengerStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Support\ManagerStation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Figures for each of today's flights (SR-47). Read only: nothing is changed here.
     * The figures stay three queries however many passengers there are: flights, passenger
     * counts, bag counts. The station list is the one airport query shared with the filter.
     */
    public function index(Request $request): View
    {
        // Same definition of "today" as the <x-todays-flights> component (SR-41): today at the
        // Manager's station (UTC without one). A saved station keeps that airport's departures.
        $station = ManagerStation::apply($request);
        $flights = Flight::departingToday($request->user())
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->orderBy('departure_at')
            ->get();

        // [flight_id => [status => number of passengers]], counted by the database: one coupon
        // per passenger per flight (B13b), so connecting passengers count on each of their flights.
        $passengerCounts = DB::table('coupons')
            ->whereIn('coupons.flight_id', $flights->modelKeys())
            ->select('coupons.flight_id', 'coupons.status', DB::raw('count(*) as total'))
            ->groupBy('coupons.flight_id', 'coupons.status')
            ->get()
            ->groupBy('flight_id')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        // [flight_id => number of bags]. A bag travels on every flight of its direction (B13b, SR-67):
        // the passenger's coupons in that direction.
        $bagCounts = DB::table('bags')
            ->join('coupons', 'coupons.passenger_id', '=', 'bags.passenger_id')
            ->join('reservation_segments', fn ($join) => $join
                ->on('reservation_segments.id', '=', 'coupons.reservation_segment_id')
                ->on('reservation_segments.direction', '=', 'bags.direction'))
            ->whereIn('coupons.flight_id', $flights->modelKeys())
            ->select('coupons.flight_id', DB::raw('count(*) as total'))
            ->groupBy('coupons.flight_id')
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

        return view('manager.dashboard', ['rows' => $rows, 'station' => $station]);
    }
}
