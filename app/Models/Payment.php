<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A card payment. Created only by PaymentService. Holds the brand, last 4 digits and gateway
 * reference: never the card number or CVC.
 */
class Payment extends Model
{
    use StoresTimesInUtc;

    /**
     * Why the gateway refused, for the screen. Not stored.
     */
    public ?string $failureMessage = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_id',
        'passenger_id',
        'refund_of_payment_id',
        'kind',
        'amount_cents',
        'breakdown',
        'currency',
        'method',
        'card_brand',
        'card_last4',
        'gateway_reference',
        'status',
        'taken_by',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'breakdown' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_payment_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_payment_id');
    }

    public function isRefund(): bool
    {
        return $this->kind === PaymentKind::Refund
            || $this->status === PaymentStatus::Refunded;
    }

    /**
     * How much of this charge has already been refunded (cents).
     */
    public function refundedCents(): int
    {
        return (int) $this->refunds()
            ->where('status', PaymentStatus::Refunded)
            ->sum('amount_cents');
    }

    public function refundableCents(): int
    {
        if ($this->status !== PaymentStatus::Succeeded) {
            return 0;
        }

        return max(0, $this->amount_cents - $this->refundedCents());
    }
}
