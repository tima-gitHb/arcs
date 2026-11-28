<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way to take or refund a payment (card only, test mode). Only the brand, last 4 digits
 * and gateway reference are kept: the card number and CVC are never stored, logged or returned.
 */
class PaymentService
{
    private const CURRENCY = 'USD';

    public function __construct(private PaymentGateway $gateway) {}

    /**
     * A declined card is not an error: it is saved as Failed, and the returned payment has
     * the reason in failureMessage.
     *
     * @param  Flight|null  $flight  Flight this charge is for (e.g. per-flight upgrade); defaults to the reservation's first flight.
     * @param  array<string, mixed>|null  $breakdown  Stored fare allocation for later partial refunds.
     */
    public function charge(
        Payments\CardDetails $card,
        int $amountCents,
        PaymentKind $kind,
        User $takenBy,
        ?Reservation $reservation = null,
        ?Passenger $passenger = null,
        ?Flight $flight = null,
        ?array $breakdown = null,
    ): Payment {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('A payment must be more than zero.');
        }
        if ($reservation === null && $passenger === null) {
            throw new InvalidArgumentException('A payment needs a reservation or a passenger.');
        }

        return DB::transaction(function () use ($card, $amountCents, $kind, $takenBy, $reservation, $passenger, $flight, $breakdown) {
            $result = $this->gateway->charge($card, $amountCents, self::CURRENCY);

            $payment = Payment::create([
                'reservation_id' => $reservation?->id,
                'passenger_id' => $passenger?->id,
                'kind' => $kind,
                'amount_cents' => $amountCents,
                'breakdown' => $breakdown,
                'currency' => self::CURRENCY,
                'method' => 'card',
                'card_brand' => $result->brand,
                'card_last4' => $result->last4,
                'gateway_reference' => $result->reference,
                'status' => $result->approved ? PaymentStatus::Succeeded : PaymentStatus::Failed,
                'taken_by' => $takenBy->id,
                'paid_at' => $result->approved ? now() : null,
            ]);
            $payment->failureMessage = $result->message;

            AuditEntry::create([
                'passenger_id' => $passenger?->id,
                'flight_id' => $flight?->id
                    ?? $reservation?->flight_id
                    ?? $passenger?->reservation->flight_id,
                'field' => 'payment',
                'old_value' => null,
                'new_value' => "{$payment->status->label()}: {$kind->label()} ".Money::format($amountCents)
                    ." ({$result->brand} ••••{$result->last4})",
                'user_id' => $takenBy->id,
                'changed_at' => now(),
            ]);

            return $payment;
        });
    }

    /**
     * Refund part or all of a successful charge. Amount is stored positive with status Refunded.
     * Total refunded across all refund rows can never exceed the original charge.
     */
    public function refund(
        Payment $original,
        int $amountCents,
        User $takenBy,
        ?string $note = null,
    ): Payment {
        if ($original->status !== PaymentStatus::Succeeded) {
            throw new InvalidArgumentException('Only a successful payment can be refunded.');
        }
        if ($original->kind === PaymentKind::Refund) {
            throw new InvalidArgumentException('A refund cannot be refunded again.');
        }
        if ($amountCents < 0) {
            throw new InvalidArgumentException('A refund amount cannot be negative.');
        }

        return DB::transaction(function () use ($original, $amountCents, $takenBy, $note) {
            $original = Payment::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            $already = (int) $original->refunds()
                ->where('status', PaymentStatus::Refunded)
                ->sum('amount_cents');
            $remaining = $original->amount_cents - $already;

            if ($amountCents > $remaining) {
                throw new InvalidArgumentException(
                    'Refund would exceed the amount paid ('.Money::format($remaining).' left).',
                );
            }

            $reference = (string) ($original->gateway_reference ?? '');
            $result = $this->gateway->refund(
                $reference,
                $amountCents,
                self::CURRENCY,
                (string) $original->card_brand,
                (string) $original->card_last4,
            );

            $payment = Payment::create([
                'reservation_id' => $original->reservation_id,
                'passenger_id' => $original->passenger_id,
                'refund_of_payment_id' => $original->id,
                'kind' => PaymentKind::Refund,
                'amount_cents' => $amountCents,
                'currency' => self::CURRENCY,
                'method' => 'card',
                'card_brand' => $original->card_brand,
                'card_last4' => $original->card_last4,
                'gateway_reference' => $result->reference,
                'status' => PaymentStatus::Refunded,
                'taken_by' => $takenBy->id,
                'paid_at' => now(),
            ]);

            $summary = Money::format($amountCents).' to '.$original->card_brand.' ••••'.$original->card_last4;
            if ($note !== null && $note !== '') {
                $summary .= ' · '.$note;
            }

            AuditEntry::create([
                'passenger_id' => $original->passenger_id,
                'flight_id' => $original->reservation?->flight_id
                    ?? $original->passenger?->reservation->flight_id,
                'field' => 'refund',
                'old_value' => Money::format($original->amount_cents),
                'new_value' => mb_substr($summary, 0, 255),
                'user_id' => $takenBy->id,
                'changed_at' => now(),
            ]);

            return $payment;
        });
    }
}
