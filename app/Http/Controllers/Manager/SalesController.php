<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SalesController extends Controller
{
    /**
     * Sales per flight (SR-64). Read only: nothing is changed here.
     * Today's flights by default, or the one flight chosen with ?flight=<id>.
     * A fixed number of queries, however many flights, passengers or payments there are:
     * all flights (picker), today's flights, seats per class, seats sold, payments.
     */
    public function index(Request $request): View
    {
        $request->validate(['flight' => ['nullable', 'integer', 'exists:flights,id']]);

        // The picker lists every flight, newest departure first.
        $flights = Flight::orderByDesc('departure_at')->get();

        $selected = $request->filled('flight') ? $flights->firstWhere('id', (int) $request->query('flight')) : null;

        // Same definition of "today" as the <x-todays-flights> component (SR-41) and the dashboard.
        $reported = $selected
            ? $flights->where('id', $selected->id)->values()
            : Flight::whereBetween('departure_at', [now()->startOfDay(), now()->endOfDay()])->orderBy('departure_at')->get();

        $capacity = $this->capacity($reported);
        $sold = $this->sold($reported);
        $revenue = $this->revenue($reported);

        $reports = $reported->map(fn (Flight $flight) => $this->report(
            $flight,
            $capacity[$flight->aircraft_id] ?? collect(),
            $sold[$flight->id] ?? collect(),
            $revenue[$flight->id] ?? collect(),
        ));

        return view('manager.sales', [
            'reports' => $reports,
            'flights' => $flights,
            'selected' => $selected,
        ]);
    }

    /**
     * [aircraft_id => [cabin class => number of seats]], counted by the database.
     *
     * @param  Collection<int, Flight>  $flights
     * @return Collection<int, Collection<string, int>>
     */
    private function capacity(Collection $flights): Collection
    {
        return DB::table('seats')
            ->whereIn('aircraft_id', $flights->pluck('aircraft_id')->unique())
            ->select('aircraft_id', 'cabin_class', DB::raw('count(*) as total'))
            ->groupBy('aircraft_id', 'cabin_class')
            ->get()
            ->groupBy('aircraft_id')
            ->map(fn ($rows) => $rows->pluck('total', 'cabin_class'));
    }

    /**
     * [flight_id => [cabin class => passengers]]. A passenger is a seat sold unless Cancelled; an Infant
     * has no seat. Boarded and NoShow passengers were sold a seat, so they count. A null class
     * (booked without one) comes back under the key ''.
     *
     * @param  Collection<int, Flight>  $flights
     * @return Collection<int, Collection<string, int>>
     */
    private function sold(Collection $flights): Collection
    {
        return DB::table('passengers')
            ->join('reservations', 'reservations.id', '=', 'passengers.reservation_id')
            ->whereIn('reservations.flight_id', $flights->modelKeys())
            ->where('passengers.status', '!=', PassengerStatus::Cancelled->value)
            ->where('passengers.type', '!=', PassengerType::Infant->value)
            ->select('reservations.flight_id', 'passengers.cabin_class', DB::raw('count(*) as total'))
            ->groupBy('reservations.flight_id', 'passengers.cabin_class')
            ->get()
            ->groupBy('flight_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [(string) $row->cabin_class => (int) $row->total]));
    }

    /**
     * [flight_id => [payment kind => cents]] from SUCCEEDED payments only.
     * A payment reaches its flight through its reservation (booking) or through its passenger's
     * reservation (check-in extras). COALESCE takes the first one that exists, so a payment with both
     * ids, or on a reservation with several passengers, is still counted once: every join is one-to-one.
     *
     * @param  Collection<int, Flight>  $flights
     * @return Collection<int, Collection<string, int>>
     */
    private function revenue(Collection $flights): Collection
    {
        $flightId = 'COALESCE(booking.flight_id, traveller.flight_id)';

        return DB::table('payments')
            ->leftJoin('reservations as booking', 'booking.id', '=', 'payments.reservation_id')
            ->leftJoin('passengers', 'passengers.id', '=', 'payments.passenger_id')
            ->leftJoin('reservations as traveller', 'traveller.id', '=', 'passengers.reservation_id')
            ->where('payments.status', PaymentStatus::Succeeded->value)
            ->whereIn(DB::raw($flightId), $flights->modelKeys())
            ->select(DB::raw($flightId.' as flight_id'), 'payments.kind', DB::raw('sum(payments.amount_cents) as total'))
            ->groupBy(DB::raw($flightId), 'payments.kind')
            ->get()
            ->groupBy('flight_id')
            ->map(fn ($rows) => $rows->pluck('total', 'kind')->map(fn ($total) => (int) $total));
    }

    /**
     * @param  Collection<string, int>  $capacity  seats per class on this flight's aircraft
     * @param  Collection<string, int>  $sold  seats sold per class ('' = class not set)
     * @param  Collection<string, int>  $revenue  cents per payment kind
     * @return array<string, mixed>
     */
    private function report(Flight $flight, Collection $capacity, Collection $sold, Collection $revenue): array
    {
        $classes = [];
        foreach (CabinClass::cases() as $cabin) {
            $classes[$cabin->value] = $this->line($sold[$cabin->value] ?? 0, $capacity[$cabin->value] ?? 0);
        }

        // Fares and fees are told apart by the payment kind only: Fare = fares; ExtraBag, ExcessBaggage
        // and Fee = fees. A Fare payment that also holds fees would still count as fares.
        $fares = $revenue[PaymentKind::Fare->value] ?? 0;
        $fees = $revenue->except(PaymentKind::Fare->value)->sum();

        return [
            'flight' => $flight,
            'classes' => $classes,
            // Passengers with no class are in no class line, but they did buy a seat: overall counts them.
            'not_set' => $sold[''] ?? 0,
            'overall' => $this->line($sold->sum(), $capacity->sum()),
            'revenue' => ['fares_cents' => $fares, 'fees_cents' => $fees, 'total_cents' => $fares + $fees],
        ];
    }

    /**
     * @return array{sold: int, capacity: int, percent: int|null}
     */
    private function line(int $sold, int $capacity): array
    {
        // Whole percent, half up, in integers: (sold x 200 + capacity) / (capacity x 2) rounded down is
        // sold / capacity x 100 rounded half up (12.5 → 13). No float, and a class with 0 seats has no percent.
        $percent = $capacity > 0 ? intdiv($sold * 200 + $capacity, $capacity * 2) : null;

        return ['sold' => $sold, 'capacity' => $capacity, 'percent' => $percent];
    }
}
