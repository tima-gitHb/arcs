<?php

namespace App\Models;

use App\Enums\PassengerStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\SegmentDirection;
use App\Support\Limits;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pnr',
        'flight_id',
        'created_by',
        'contact_email',
        'contact_phone',
    ];

    protected static function booted(): void
    {
        // The reservation's flight is the first outbound segment (B13b). JourneyService adds the others.
        static::created(function (Reservation $reservation) {
            $reservation->segments()->create([
                'flight_id' => $reservation->flight_id,
                'sequence' => 1,
                'direction' => SegmentDirection::Outbound,
                'leg' => 1,
            ]);
        });
    }

    /**
     * The first flight of the journey (the first outbound segment). For a journey of several
     * flights use segments().
     */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * The flights of the journey, in order (B13b).
     */
    public function segments(): HasMany
    {
        return $this->hasMany(ReservationSegment::class)->orderBy('reservation_segments.sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The successful fare payment for this booking, if any.
     */
    public function farePayment(): ?Payment
    {
        return $this->payments
            ->where('kind', PaymentKind::Fare)
            ->where('status', PaymentStatus::Succeeded)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Display-only: 10+ passengers show a Group badge (validation #4).
     */
    public function isGroup(): bool
    {
        return $this->passengers()->count() >= Limits::groupBookingMinPassengers();
    }

    /**
     * Every passenger's coupons are Cancelled (Sprint 4 Feature 2).
     */
    public function isCancelled(): bool
    {
        $this->loadMissing('passengers.coupons');

        if ($this->passengers->isEmpty()) {
            return false;
        }

        return $this->passengers->every(
            fn ($passenger) => $passenger->coupons->isNotEmpty()
                && $passenger->coupons->every(fn ($coupon) => $coupon->status === PassengerStatus::Cancelled),
        );
    }
}
