<?php

namespace App\Http\Controllers\Manager;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Payment;
use App\Models\User;
use App\Support\ManagerStation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentReportController extends Controller
{
    /**
     * Payments report (SR-57). Read only.
     * Filters are GET parameters: flight_id, user_id (who took the payment), date (Y-m-d).
     * The flight is the reservation's flight, or the passenger's reservation's flight when
     * the payment has no reservation — the same COALESCE as SalesController, so a payment
     * is listed once. Totals count Succeeded payments in the filtered set only.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'flight_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $station = ManagerStation::apply($request);
        $payments = $this->payments($filters, $request->user(), $station);

        $succeeded = $payments->where('status', PaymentStatus::Succeeded);
        $refunded = $payments->where('status', PaymentStatus::Refunded);
        $totals = [];
        foreach (PaymentKind::cases() as $kind) {
            if ($kind === PaymentKind::Refund) {
                $totals[$kind->value] = (int) $refunded->sum('amount_cents');

                continue;
            }
            $totals[$kind->value] = (int) $succeeded->where('kind', $kind)->sum('amount_cents');
        }

        $failed = $payments->where('status', PaymentStatus::Failed);
        $chargeCents = (int) $succeeded->sum('amount_cents');
        $refundCents = (int) $refunded->sum('amount_cents');

        $status = $request->query('status');

        return view('manager.payments', [
            'payments' => $payments->when(
                is_string($status) && $status === 'paid',
                fn ($c) => $c->where('status', PaymentStatus::Succeeded)
            )->when(
                is_string($status) && $status === 'failed',
                fn ($c) => $c->where('status', PaymentStatus::Failed)
            )->when(
                is_string($status) && $status === 'refunded',
                fn ($c) => $c->where('status', PaymentStatus::Refunded)
            )->values(),
            'totals' => $totals,
            'grandCents' => $chargeCents - $refundCents,
            'failedCount' => $failed->count(),
            'failedCents' => (int) $failed->sum('amount_cents'),
            'flights' => Flight::query()
                ->when($station, fn ($query) => $query->departingFrom($station))
                ->orderByDesc('departure_at')
                ->get(),
            'staff' => User::orderBy('name')->get(),
            'station' => $station,
            'statusFilter' => is_string($status) ? $status : '',
        ]);
    }

    public function show(Payment $payment, Request $request): View
    {
        ManagerStation::apply($request);
        $payment->load(['reservation.flight', 'reservation.passengers', 'passenger.reservation.flight', 'takenBy']);

        $reservation = $payment->reservation ?? $payment->passenger?->reservation;
        $attempts = collect();
        if ($reservation !== null) {
            $attempts = Payment::query()
                ->where(function ($query) use ($reservation) {
                    $query->where('reservation_id', $reservation->id)
                        ->orWhereIn('passenger_id', $reservation->passengers()->pluck('id'));
                })
                ->with('takenBy')
                ->orderByDesc('created_at')
                ->get();
        }

        $payment->loadMissing(['refundOf', 'refunds']);

        return view('manager.payments-show', [
            'payment' => $payment,
            'reservation' => $reservation,
            'attempts' => $attempts,
        ]);
    }

    /**
     * The filtered payments, with every relation the page reads loaded for the whole list.
     *
     * The date is a day at the Manager's station (UTC without one), B13a.
     *
     * @param  array{flight_id?: int, user_id?: int, date?: string}  $filters
     * @return Collection<int, Payment>
     */
    private function payments(array $filters, User $viewer, ?string $station): Collection
    {
        $flightId = 'COALESCE(booking.flight_id, traveller.flight_id)';

        return Payment::query()
            ->select('payments.*')
            ->leftJoin('reservations as booking', 'booking.id', '=', 'payments.reservation_id')
            ->leftJoin('passengers', 'passengers.id', '=', 'payments.passenger_id')
            ->leftJoin('reservations as traveller', 'traveller.id', '=', 'passengers.reservation_id')
            ->with(['reservation.flight', 'passenger.reservation.flight', 'takenBy'])
            ->when($filters['flight_id'] ?? null, fn (Builder $query, int $id) => $query->whereRaw($flightId.' = ?', [$id]))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $id) => $query->where('payments.taken_by', $id))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date) => $query->whereBetween('payments.created_at', $viewer->stationDay($date)))
            // The same COALESCE flight, limited to departures from the station. A subquery, not a second query.
            ->when($station, fn (Builder $query) => $query->whereIn(
                DB::raw($flightId),
                Flight::query()->departingFrom($station)->select('flights.id'),
            ))
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->get();
    }
}
