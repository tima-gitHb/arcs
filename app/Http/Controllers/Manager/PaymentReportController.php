<?php

namespace App\Http\Controllers\Manager;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $payments = $this->payments($filters);

        $succeeded = $payments->where('status', PaymentStatus::Succeeded);
        $totals = [];
        foreach (PaymentKind::cases() as $kind) {
            $totals[$kind->value] = (int) $succeeded->where('kind', $kind)->sum('amount_cents');
        }

        $failed = $payments->where('status', PaymentStatus::Failed);

        return view('manager.payments', [
            'payments' => $payments,
            'totals' => $totals,
            'grandCents' => array_sum($totals),
            'failedCount' => $failed->count(),
            'failedCents' => (int) $failed->sum('amount_cents'),
            'flights' => Flight::orderByDesc('departure_at')->get(),
            'staff' => User::orderBy('name')->get(),
        ]);
    }

    /**
     * The filtered payments, with every relation the page reads loaded for the whole list.
     *
     * @param  array{flight_id?: int, user_id?: int, date?: string}  $filters
     * @return Collection<int, Payment>
     */
    private function payments(array $filters): Collection
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
            ->when($filters['date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('payments.created_at', $date))
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->get();
    }
}
