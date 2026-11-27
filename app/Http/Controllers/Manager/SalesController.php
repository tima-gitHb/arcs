<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\SalesFilterRequest;
use App\Models\Flight;
use App\Models\User;
use App\Support\ManagerStation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    /**
     * Sales per flight (SR-64) plus the revenue dashboard (M15). Read only: nothing is changed here.
     * No filters: today's flights, today at the viewer's station (UTC with no station).
     * ?flight= alone still shows that one flight on any day. from/to replace today.
     * route and station always narrow the set. The dashboard is summed from the payments
     * query below, so filters do not add a query.
     */
    public function index(SalesFilterRequest $request): View
    {
        $filters = [
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'route' => $request->validated('route'),
            'station' => ManagerStation::apply($request),
            'flight' => $request->validated('flight'),
        ];

        // Every flight, for the route check. The picker below keeps only the station's departures.
        $flights = Flight::orderByDesc('departure_at')->get();

        if ($filters['route'] !== null && ! $this->routeIsFlown($flights, $filters['route'])) {
            throw ValidationException::withMessages([
                'route' => 'Choose a route a flight uses.',
            ]);
        }

        $selected = $filters['flight'] !== null
            ? $flights->firstWhere('id', (int) $filters['flight'])
            : null;

        $reported = $this->reportedFlights($flights, $request->user(), $selected, $filters);

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
            'flights' => $filters['station'] === null
                ? $flights
                : $flights->where('origin', $filters['station'])->values(),
            'selected' => $selected,
            'filters' => $filters,
            'routes' => $this->routes($flights),
            'stations' => ManagerStation::airports($request)->pluck('code'),
            'timezone' => $request->user()->timezone(),
            'dashboard' => $this->dashboard($revenue),
            // The "today" list is the page with nothing chosen. A flight, a date, a route or a station replaces it.
            'defaultPage' => $selected === null
                && $filters['from'] === null
                && $filters['to'] === null
                && $filters['route'] === null
                && $filters['station'] === null,
        ]);
    }

    /**
     * The flights the report and the dashboard cover, earliest departure first.
     * Dates are calendar days in the viewer's station time, compared to departure_at (UTC).
     * A station keeps departures from that airport, the same rule as Flight::departingFrom().
     *
     * @param  Collection<int, Flight>  $flights
     * @param  array{from: ?string, to: ?string, route: ?string, station: ?string, flight: mixed}  $filters
     * @return Collection<int, Flight>
     */
    private function reportedFlights(Collection $flights, User $user, ?Flight $selected, array $filters): Collection
    {
        $reported = $selected !== null ? $flights->where('id', $selected->id) : $flights;

        if ($filters['from'] !== null || $filters['to'] !== null) {
            $start = $filters['from'] === null ? null : $user->stationDay($filters['from'])[0];
            $end = $filters['to'] === null ? null : $user->stationDay($filters['to'])[1];
            $reported = $reported->filter(fn (Flight $flight) => $this->departsBetween($flight, $start, $end));
        } elseif ($selected === null) {
            [$start, $end] = $user->stationDay();
            $reported = $reported->filter(fn (Flight $flight) => $this->departsBetween($flight, $start, $end));
        }

        if ($filters['route'] !== null) {
            [$origin, $destination] = explode('-', $filters['route'], 2);
            $reported = $reported->where('origin', $origin)->where('destination', $destination);
        }

        if ($filters['station'] !== null) {
            $reported = $reported->where('origin', $filters['station']);
        }

        return $reported->sortBy(fn (Flight $flight) => $flight->departure_at->getTimestamp())->values();
    }

    /**
     * True when departure_at falls inside the UTC instants of the viewer's dates. Either end may be open.
     */
    private function departsBetween(Flight $flight, ?Carbon $start, ?Carbon $end): bool
    {
        if ($start !== null && $flight->departure_at->lt($start)) {
            return false;
        }

        return $end === null || $flight->departure_at->lte($end);
    }

    /**
     * @param  Collection<int, Flight>  $flights
     */
    private function routeIsFlown(Collection $flights, string $route): bool
    {
        return $flights->contains(fn (Flight $flight) => $flight->origin.'-'.$flight->destination === $route);
    }

    /**
     * One "BEY-CDG" per directed pair that a flight uses. A plain list: the flight collection
     * must not be asked to merge these strings.
     *
     * @param  Collection<int, Flight>  $flights
     * @return Collection<int, string>
     */
    private function routes(Collection $flights): Collection
    {
        return collect($flights->map(fn (Flight $flight) => $flight->origin.'-'.$flight->destination)->all())
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Ticket revenue is Fare. The four other kinds are extras, each on its own and as a sum.
     * Grand total is the ticket plus the extras. Failed payments are already excluded by revenue().
     *
     * @param  Collection<int, Collection<string, int>>  $revenue  cents per kind, per flight
     * @return array<string, int>
     */
    private function dashboard(Collection $revenue): array
    {
        $totals = [];
        foreach (PaymentKind::cases() as $kind) {
            $totals[$kind->value] = 0;
        }

        foreach ($revenue as $kinds) {
            foreach ($kinds as $kind => $cents) {
                $totals[$kind] = ($totals[$kind] ?? 0) + $cents;
            }
        }

        $extras = $totals[PaymentKind::ExtraBag->value]
            + $totals[PaymentKind::ExcessBaggage->value]
            + $totals[PaymentKind::Fee->value]
            + $totals[PaymentKind::Upgrade->value];

        $refunds = $totals[PaymentKind::Refund->value] ?? 0;
        $totals['extras'] = $extras;
        // Net revenue: charges − refunds (Sprint 4 Feature 2).
        $totals['grand'] = $totals[PaymentKind::Fare->value] + $extras - $refunds;

        return $totals;
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
        // One coupon per passenger per flight (B13b): a connecting passenger is a seat sold on each flight.
        return DB::table('coupons')
            ->join('passengers', 'passengers.id', '=', 'coupons.passenger_id')
            ->whereIn('coupons.flight_id', $flights->modelKeys())
            ->where('coupons.status', '!=', PassengerStatus::Cancelled->value)
            ->where('passengers.type', '!=', PassengerType::Infant->value)
            ->select('coupons.flight_id', 'passengers.cabin_class', DB::raw('count(*) as total'))
            ->groupBy('coupons.flight_id', 'passengers.cabin_class')
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
            ->whereIn('payments.status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
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
        // and Fee = fees. Refunds reduce net total (Sprint 4 Feature 2).
        $fares = $revenue[PaymentKind::Fare->value] ?? 0;
        $refunds = $revenue[PaymentKind::Refund->value] ?? 0;
        $fees = $revenue->except([PaymentKind::Fare->value, PaymentKind::Refund->value])->sum();

        return [
            'flight' => $flight,
            'classes' => $classes,
            // Passengers with no class are in no class line, but they did buy a seat: overall counts them.
            'not_set' => $sold[''] ?? 0,
            'overall' => $this->line($sold->sum(), $capacity->sum()),
            'revenue' => [
                'fares_cents' => $fares,
                'fees_cents' => $fees,
                'refunds_cents' => $refunds,
                'total_cents' => $fares + $fees - $refunds,
            ],
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
