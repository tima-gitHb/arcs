<?php

namespace App\Http\Controllers\Manager;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Payment;
use App\Support\ManagerStation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $period = $request->query('period', 'today');
        if (! in_array($period, ['today', '7d', '30d'], true)) {
            $period = 'today';
        }

        [$from, $to] = $this->periodBounds($request, $period);

        $periodFlights = Flight::query()
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->whereBetween('departure_at', [$from, $to])
            ->orderBy('departure_at')
            ->get();

        $flights = Flight::departingToday($request->user())
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->orderBy('departure_at')
            ->get();

        // [flight_id => [status => number of passengers]], counted by the database: one coupon
        // per passenger per flight (B13b), so connecting passengers count on each of their flights.
        $allIds = $flights->modelKeys();
        $passengerCounts = DB::table('coupons')
            ->whereIn('coupons.flight_id', $allIds)
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
            ->whereIn('coupons.flight_id', $allIds)
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

        $kpis = $this->kpis($periodFlights, $from, $to, $station);
        [$prevFrom, $prevTo] = $this->previousPeriodBounds($from, $to, $period);
        $prevFlights = Flight::query()
            ->when($station, fn ($query) => $query->departingFrom($station))
            ->whereBetween('departure_at', [$prevFrom, $prevTo])
            ->orderBy('departure_at')
            ->get();
        $prevKpis = $this->kpis($prevFlights, $prevFrom, $prevTo, $station);
        $kpis['deltas'] = $this->kpiDeltas($kpis, $prevKpis, $prevFlights->isNotEmpty() || $prevKpis['ticket_cents'] > 0 || $prevKpis['extras_cents'] > 0);

        $needsAttention = [
            'boarding' => $flights->filter(fn (Flight $f) => $f->status === FlightStatus::Boarding)
                ->filter(function (Flight $flight) use ($passengerCounts) {
                    $checked = (int) ($passengerCounts[$flight->id][PassengerStatus::CheckedIn->value] ?? 0);

                    return $checked > 0;
                })->values(),
            'checkin_closing' => $flights->filter(function (Flight $flight) {
                return $flight->status === FlightStatus::CheckinOpen
                    && $flight->checkin_closes_at !== null
                    && $flight->checkin_closes_at->between(now(), now()->addMinutes(30));
            })->values(),
            'failed_payments' => Payment::query()
                ->where('status', PaymentStatus::Failed)
                ->whereBetween('created_at', $request->user()->stationDay(now()->toDateString()))
                ->count(),
        ];

        return view('manager.dashboard', [
            'rows' => $rows,
            'station' => $station,
            'period' => $period,
            'kpis' => $kpis,
            'needsAttention' => $needsAttention,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodBounds(Request $request, string $period): array
    {
        [$todayStart, $todayEnd] = $request->user()->stationDay(now()->toDateString());

        return match ($period) {
            '7d' => [$todayStart->copy()->subDays(6), $todayEnd],
            '30d' => [$todayStart->copy()->subDays(29), $todayEnd],
            default => [$todayStart, $todayEnd],
        };
    }

    /**
     * Same length as the current period, ending just before it starts.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousPeriodBounds(Carbon $from, Carbon $to, string $period): array
    {
        $lengthDays = match ($period) {
            '7d' => 7,
            '30d' => 30,
            default => 1,
        };

        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($lengthDays);

        return [$prevFrom, $prevTo];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{label: string, direction: string}>
     */
    private function kpiDeltas(array $current, array $previous, bool $hasPreviousData): array
    {
        if (! $hasPreviousData) {
            $empty = ['label' => 'No earlier data', 'direction' => 'none'];

            return [
                'ticket' => $empty,
                'extras' => $empty,
                'avg_load' => $empty,
                'on_time' => $empty,
            ];
        }

        return [
            'ticket' => $this->moneyDelta((int) $current['ticket_cents'], (int) $previous['ticket_cents']),
            'extras' => $this->moneyDelta((int) $current['extras_cents'], (int) $previous['extras_cents']),
            'avg_load' => $this->pointsDelta((int) $current['avg_load'], (int) $previous['avg_load'], ' pts'),
            'on_time' => $this->pointsDelta((int) $current['on_time'], (int) $previous['on_time'], ''),
        ];
    }

    /**
     * @return array{label: string, direction: string}
     */
    private function moneyDelta(int $current, int $previous): array
    {
        $diff = $current - $previous;
        if ($diff === 0) {
            return ['label' => 'No change', 'direction' => 'flat'];
        }

        return [
            'label' => ($diff > 0 ? '+' : '−').Money::format(abs($diff)),
            'direction' => $diff > 0 ? 'up' : 'down',
        ];
    }

    /**
     * @return array{label: string, direction: string}
     */
    private function pointsDelta(int $current, int $previous, string $suffix): array
    {
        $diff = $current - $previous;
        if ($diff === 0) {
            return ['label' => 'No change', 'direction' => 'flat'];
        }

        return [
            'label' => ($diff > 0 ? '+' : '').$diff.$suffix,
            'direction' => $diff > 0 ? 'up' : 'down',
        ];
    }

    /**
     * @param  Collection<int, Flight>  $flights
     * @return array<string, mixed>
     */
    private function kpis($flights, Carbon $from, Carbon $to, ?string $station): array
    {
        $flightIds = $flights->modelKeys();
        $flightIdExpr = 'COALESCE(booking.flight_id, traveller.flight_id)';

        $payments = Payment::query()
            ->select('payments.*')
            ->leftJoin('reservations as booking', 'booking.id', '=', 'payments.reservation_id')
            ->leftJoin('passengers', 'passengers.id', '=', 'payments.passenger_id')
            ->leftJoin('reservations as traveller', 'traveller.id', '=', 'passengers.reservation_id')
            ->where('payments.status', PaymentStatus::Succeeded)
            ->whereBetween('payments.paid_at', [$from, $to])
            ->when($station !== null || $flightIds !== [], function ($query) use ($flightIds, $flightIdExpr) {
                if ($flightIds === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->whereIn(DB::raw($flightIdExpr), $flightIds);
            })
            ->get();

        $ticketCents = (int) $payments->where('kind', PaymentKind::Fare)->sum('amount_cents');
        $extrasCents = (int) $payments->whereIn('kind', [
            PaymentKind::ExtraBag,
            PaymentKind::ExcessBaggage,
            PaymentKind::Fee,
            PaymentKind::Upgrade,
        ])->sum('amount_cents');

        $periodPassengerCounts = DB::table('coupons')
            ->whereIn('flight_id', $flightIds === [] ? [0] : $flightIds)
            ->select('flight_id', 'status', DB::raw('count(*) as total'))
            ->groupBy('flight_id', 'status')
            ->get()
            ->groupBy('flight_id')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        $loadFactors = $flights->map(function (Flight $flight) use ($periodPassengerCounts) {
            $counts = $periodPassengerCounts[$flight->id] ?? collect();
            $checked = (int) ($counts[PassengerStatus::CheckedIn->value] ?? 0)
                + (int) ($counts[PassengerStatus::Boarded->value] ?? 0)
                + (int) ($counts[PassengerStatus::NoShow->value] ?? 0);
            $total = $checked + (int) ($counts[PassengerStatus::Booked->value] ?? 0);

            return $total > 0 ? (int) round($checked / $total * 100) : null;
        })->filter(fn ($v) => $v !== null);

        $avgLoad = $loadFactors->isEmpty() ? 0 : (int) round($loadFactors->avg());

        $onTimeStatuses = [
            FlightStatus::Scheduled,
            FlightStatus::CheckinOpen,
            FlightStatus::Boarding,
            FlightStatus::Closed,
            FlightStatus::Departed,
        ];
        $onTime = $flights->filter(fn (Flight $f) => in_array($f->status, $onTimeStatuses, true))->count();
        $flightCount = $flights->count();

        return [
            'ticket' => Money::format($ticketCents),
            'ticket_cents' => $ticketCents,
            'extras' => Money::format($extrasCents),
            'extras_cents' => $extrasCents,
            'avg_load' => $avgLoad,
            'on_time' => $onTime,
            'flight_count' => $flightCount,
        ];
    }
}
