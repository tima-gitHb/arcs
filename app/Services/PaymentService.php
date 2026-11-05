<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\AuditEntry;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way to take a payment (card only, test mode). Only the brand, last 4 digits and
 * gateway reference are kept: the card number and CVC are never stored, logged or returned.
 * There are no refunds.
 */
class PaymentService
{
    private const CURRENCY = 'USD';

    public function __construct(private PaymentGateway $gateway) {}

    /**
     * A declined card is not an error: it is saved as Failed, and the returned payment has
     * the reason in failureMessage.
     */
    public function charge(
        Payments\CardDetails $card,
        int $amountCents,
        PaymentKind $kind,
        User $takenBy,
        ?Reservation $reservation = null,
        ?Passenger $passenger = null,
    ): Payment {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('A payment must be more than zero.');
        }
        if ($reservation === null && $passenger === null) {
            throw new InvalidArgumentException('A payment needs a reservation or a passenger.');
        }

        return DB::transaction(function () use ($card, $amountCents, $kind, $takenBy, $reservation, $passenger) {
            $result = $this->gateway->charge($card, $amountCents, self::CURRENCY);

            $payment = Payment::create([
                'reservation_id' => $reservation?->id,
                'passenger_id' => $passenger?->id,
                'kind' => $kind,
                'amount_cents' => $amountCents,
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
                'flight_id' => $reservation?->flight_id ?? $passenger?->reservation->flight_id,
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
}
