<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\RefundNotAllowed;
use App\Mail\RefundConfirmationMail;
use App\Models\AuditEntry;
use App\Models\Coupon;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\FlightAction;
use App\Support\FlightStatusGate;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Cancel-and-refund for reservation staff (Sprint 4 Feature 2).
 * Money moves only through PaymentService; statuses only through StatusService.
 */
class RefundService
{
    public function __construct(
        private FareService $fares,
        private PaymentService $payments,
        private StatusService $statuses,
    ) {}

    /**
     * @param  list<int>  $passengerIds
     * @param  list<int>  $legs  empty = every remaining booked leg for those passengers
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   paid_cents: int,
     *   fee_cents: int,
     *   refund_cents: int,
     *   coupon_ids: list<int>,
     *   payment: Payment|null
     * }
     */
    public function preview(Reservation $reservation, array $passengerIds, array $legs = []): array
    {
        $reservation->loadMissing([
            'passengers.coupons.flight',
            'passengers.coupons.segment',
            'segments.flight',
            'payments',
        ]);

        $targets = $this->targetCoupons($reservation, $passengerIds, $legs);
        $this->assertEligible($targets);

        $payment = $reservation->farePayment();
        $breakdown = $this->breakdownItems($reservation, $payment);
        $selected = $this->selectedFareCents($breakdown, $targets);

        $feeUnits = 0;
        foreach ($targets as $coupon) {
            $passenger = $coupon->passenger;
            $farePart = $selected['by_coupon'][$coupon->id] ?? 0;
            if ($passenger->type === PassengerType::Infant && $farePart === 0) {
                continue;
            }
            $feeUnits++;
        }

        $feeCents = $feeUnits > 0
            ? $this->fares->cancellationFee(1, 1)->totalCents * $feeUnits
            : 0;

        $paidPart = $selected['total'];
        // When cancelling every remaining booked coupon for a passenger, include their extras.
        $extras = $this->extrasForFullyCancelledPassengers($breakdown, $reservation, $passengerIds, $targets);
        $paidPart += $extras;

        $refundable = $payment?->refundableCents() ?? 0;
        $rawRefund = max(0, $paidPart - $feeCents);
        $refundCents = min($rawRefund, $refundable);

        $lines = [];
        foreach ($targets as $coupon) {
            $passenger = $coupon->passenger;
            $farePart = $selected['by_coupon'][$coupon->id] ?? 0;
            $unitFee = ($passenger->type === PassengerType::Infant && $farePart === 0)
                ? 0
                : $this->fares->cancellationFee(1, 1)->totalCents;
            $lines[] = [
                'passenger_id' => $passenger->id,
                'passenger_name' => trim($passenger->first_name.' '.$passenger->last_name),
                'flight_id' => $coupon->flight_id,
                'flight_no' => $coupon->flight->flight_no,
                'leg' => (int) ($coupon->segment?->leg ?? 1),
                'coupon_id' => $coupon->id,
                'paid_cents' => $farePart,
                'fee_cents' => $unitFee,
                'refund_cents' => max(0, $farePart - $unitFee),
            ];
        }

        return [
            'lines' => $lines,
            'paid_cents' => $paidPart,
            'fee_cents' => $feeCents,
            'refund_cents' => $refundCents,
            'extras_cents' => $extras,
            'coupon_ids' => $targets->pluck('id')->all(),
            'payment' => $payment,
        ];
    }

    /**
     * @param  list<int>  $passengerIds
     * @param  list<int>  $legs
     * @return array{refund: Payment|null, preview: array<string, mixed>}
     */
    public function confirm(Reservation $reservation, User $by, array $passengerIds, array $legs = []): array
    {
        return DB::transaction(function () use ($reservation, $by, $passengerIds, $legs) {
            $preview = $this->preview($reservation, $passengerIds, $legs);
            $targets = Coupon::query()
                ->whereIn('id', $preview['coupon_ids'])
                ->with(['passenger', 'flight', 'segment'])
                ->get();

            $this->assertEligible($targets);

            $byPassenger = $targets->groupBy('passenger_id');
            foreach ($byPassenger as $passengerId => $coupons) {
                $passenger = $coupons->first()->passenger;
                $this->statuses->cancelCoupons($passenger, $by, $coupons);
            }

            $refundPayment = null;
            $original = $preview['payment'];
            if ($original !== null && $preview['refund_cents'] > 0) {
                $note = $this->auditNote($preview);
                $refundPayment = $this->payments->refund($original, $preview['refund_cents'], $by, $note);
            } elseif ($original !== null && $preview['refund_cents'] === 0 && $preview['paid_cents'] > 0) {
                // Fee ate the fare: still audit the zero refund.
                AuditEntry::create([
                    'flight_id' => $reservation->flight_id,
                    'field' => 'refund',
                    'old_value' => Money::format($preview['paid_cents']),
                    'new_value' => 'Refund $0.00 (fee ≥ paid part)',
                    'user_id' => $by->id,
                    'changed_at' => now(),
                ]);
            }

            AuditEntry::create([
                'flight_id' => $reservation->flight_id,
                'field' => 'cancel_refund',
                'old_value' => null,
                'new_value' => mb_substr($this->auditNote($preview), 0, 255),
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            if ($reservation->contact_email && $preview['refund_cents'] >= 0 && $original !== null) {
                Mail::to($reservation->contact_email)->send(new RefundConfirmationMail(
                    $reservation->fresh(['passengers', 'segments.flight']),
                    $refundPayment,
                    $original,
                    $preview,
                ));
            }

            return ['refund' => $refundPayment, 'preview' => $preview];
        });
    }

    /**
     * @param  list<int>  $passengerIds
     * @param  list<int>  $legs
     * @return Collection<int, Coupon>
     */
    private function targetCoupons(Reservation $reservation, array $passengerIds, array $legs): Collection
    {
        $passengerIds = array_values(array_unique(array_map('intval', $passengerIds)));
        if ($passengerIds === []) {
            throw new RefundNotAllowed('Select at least one passenger.');
        }

        $passengers = $reservation->passengers->whereIn('id', $passengerIds);
        if ($passengers->count() !== count($passengerIds)) {
            throw new RefundNotAllowed('Passenger is not on this booking.');
        }

        $legFilter = array_values(array_unique(array_map('intval', $legs)));

        $targets = collect();
        foreach ($passengers as $passenger) {
            $active = $passenger->coupons
                ->filter(fn (Coupon $c) => $c->status !== PassengerStatus::Cancelled)
                ->values();

            if ($active->isEmpty()) {
                throw new RefundNotAllowed(
                    trim($passenger->first_name.' '.$passenger->last_name).' has no booked flights to cancel.',
                );
            }

            $activeLegs = $active
                ->map(fn (Coupon $c) => (int) ($c->segment?->leg ?? 1))
                ->unique()
                ->sort()
                ->values()
                ->all();

            $cancelLegs = $legFilter === [] ? $activeLegs : $legFilter;

            foreach ($cancelLegs as $leg) {
                if (! in_array($leg, $activeLegs, true)) {
                    throw new RefundNotAllowed("Leg {$leg} is not booked for {$passenger->first_name} {$passenger->last_name}.");
                }
            }

            // Multi-city: later legs only — cancelled legs must be a suffix of still-active legs.
            $bookedOnly = $active->filter(fn (Coupon $c) => $c->status === PassengerStatus::Booked);
            $bookedLegs = $bookedOnly
                ->map(fn (Coupon $c) => (int) ($c->segment?->leg ?? 1))
                ->unique()
                ->sort()
                ->values()
                ->all();
            if (count($bookedLegs) > 1 && $cancelLegs !== $bookedLegs && $legFilter !== []) {
                $this->assertSuffixLegs($bookedLegs, array_values(array_intersect($cancelLegs, $bookedLegs)), $passenger);
            }

            foreach ($active as $coupon) {
                $leg = (int) ($coupon->segment?->leg ?? 1);
                if (in_array($leg, $cancelLegs, true)) {
                    $targets->push($coupon);
                }
            }
        }

        if ($targets->isEmpty()) {
            throw new RefundNotAllowed('Nothing to cancel.');
        }

        return $targets->values();
    }

    /**
     * @param  list<int>  $bookedLegs
     * @param  list<int>  $cancelLegs
     */
    private function assertSuffixLegs(array $bookedLegs, array $cancelLegs, Passenger $passenger): void
    {
        sort($bookedLegs);
        $cancel = $cancelLegs;
        sort($cancel);
        $suffixLen = count($cancel);
        $expected = array_slice($bookedLegs, -$suffixLen);

        if ($cancel !== $expected) {
            throw new RefundNotAllowed(
                'Only later legs can be dropped (e.g. cancel leg '
                .end($bookedLegs).' while keeping earlier ones).',
            );
        }
    }

    /**
     * @param  Collection<int, Coupon>  $coupons
     */
    private function assertEligible(Collection $coupons): void
    {
        foreach ($coupons as $coupon) {
            $coupon->loadMissing(['passenger', 'flight']);
            $name = trim($coupon->passenger->first_name.' '.$coupon->passenger->last_name);

            if ($coupon->status !== PassengerStatus::Booked) {
                $label = $coupon->status->label();
                throw new RefundNotAllowed(
                    "{$name} is {$label} on {$coupon->flight->flight_no} — cannot refund.",
                );
            }

            if (! FlightStatusGate::allows($coupon->flight, FlightAction::Refund)) {
                $label = $coupon->flight->status->label();
                throw new RefundNotAllowed(
                    "{$coupon->flight->flight_no} is {$label} — cannot refund.",
                );
            }
        }
    }

    /**
     * @return list<array{passenger_id: int, flight_id: int, leg: int, amount_cents: int, type?: string}>
     */
    private function breakdownItems(Reservation $reservation, ?Payment $payment): array
    {
        if ($payment !== null && is_array($payment->breakdown) && isset($payment->breakdown['items'])) {
            return $payment->breakdown['items'];
        }

        // Fallback when the charge has no snapshot: structure from FareService, scaled to what was paid.
        $items = $this->fares->passengerFlightBreakdown($reservation);
        if ($payment === null || $items === []) {
            return $items;
        }

        $sum = array_sum(array_column($items, 'amount_cents'));
        if ($sum <= 0) {
            return $items;
        }

        return $this->scaleItems($items, $payment->amount_cents, $sum);
    }

    /**
     * @param  list<array{passenger_id: int, flight_id: int, leg: int, amount_cents: int, type?: string}>  $items
     * @return list<array{passenger_id: int, flight_id: int, leg: int, amount_cents: int, type?: string}>
     */
    private function scaleItems(array $items, int $target, int $sum): array
    {
        $raw = [];
        $floors = [];
        foreach ($items as $i => $item) {
            $exact = $target * $item['amount_cents'] / $sum;
            $floors[$i] = (int) floor($exact);
            $raw[$i] = $exact - $floors[$i];
        }
        $left = $target - array_sum($floors);
        arsort($raw);
        foreach (array_keys($raw) as $i) {
            if ($left <= 0) {
                break;
            }
            $floors[$i]++;
            $left--;
        }
        foreach ($items as $i => $item) {
            $items[$i]['amount_cents'] = $floors[$i];
        }

        return $items;
    }

    /**
     * @param  list<array{passenger_id: int, flight_id: int, leg: int, amount_cents: int}>  $breakdown
     * @param  Collection<int, Coupon>  $targets
     * @return array{total: int, by_coupon: array<int, int>}
     */
    private function selectedFareCents(array $breakdown, Collection $targets): array
    {
        $byCoupon = [];
        $total = 0;

        foreach ($targets as $coupon) {
            $cents = 0;
            foreach ($breakdown as $item) {
                if (($item['type'] ?? 'fare') !== 'fare') {
                    continue;
                }
                if ((int) $item['passenger_id'] === (int) $coupon->passenger_id
                    && (int) $item['flight_id'] === (int) $coupon->flight_id) {
                    $cents += (int) $item['amount_cents'];
                }
            }
            $byCoupon[$coupon->id] = $cents;
            $total += $cents;
        }

        return ['total' => $total, 'by_coupon' => $byCoupon];
    }

    /**
     * @param  list<array<string, mixed>>  $breakdown
     * @param  list<int>  $passengerIds
     * @param  Collection<int, Coupon>  $targets
     */
    private function extrasForFullyCancelledPassengers(
        array $breakdown,
        Reservation $reservation,
        array $passengerIds,
        Collection $targets,
    ): int {
        $extras = 0;
        $targetIds = $targets->pluck('id')->all();

        foreach ($passengerIds as $passengerId) {
            $passenger = $reservation->passengers->firstWhere('id', $passengerId);
            if ($passenger === null) {
                continue;
            }
            $remainingBooked = $passenger->coupons
                ->filter(fn (Coupon $c) => $c->status === PassengerStatus::Booked
                    && ! in_array($c->id, $targetIds, true));
            if ($remainingBooked->isNotEmpty()) {
                continue;
            }
            foreach ($breakdown as $item) {
                if (($item['type'] ?? 'fare') === 'extra'
                    && (int) $item['passenger_id'] === (int) $passengerId) {
                    $extras += (int) $item['amount_cents'];
                }
            }
        }

        return $extras;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function auditNote(array $preview): string
    {
        $names = collect($preview['lines'])->pluck('passenger_name')->unique()->implode(', ');
        $flights = collect($preview['lines'])->pluck('flight_no')->unique()->implode(', ');

        return "Cancel {$names} on {$flights}; fee "
            .Money::format($preview['fee_cents']).'; refund '
            .Money::format($preview['refund_cents']);
    }
}
