<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card payment. Created only by PaymentService. Holds the brand, last 4 digits and gateway
 * reference: never the card number or CVC.
 */
class Payment extends Model
{
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
        'kind',
        'amount_cents',
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
}
