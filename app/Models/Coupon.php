<?php

namespace App\Models;

use App\Enums\PassengerStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A passenger on one segment of the journey (B13b): status, check-in and boarding are per coupon.
 * 'status', 'checked_in_at' and 'boarded_at' are not fillable: only StatusService changes them (SR-26).
 */
class Coupon extends Model
{
    use StoresTimesInUtc;

    /**
     * @var list<string>
     */
    protected $fillable = ['passenger_id', 'reservation_segment_id', 'flight_id'];

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'Booked'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PassengerStatus::class,
            'checked_in_at' => 'datetime',
            'boarded_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ReservationSegment::class, 'reservation_segment_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function seatAssignment(): HasOne
    {
        return $this->hasOne(SeatAssignment::class);
    }

    public function boardingPasses(): HasMany
    {
        return $this->hasMany(BoardingPass::class);
    }

    public function activeBoardingPass(): HasOne
    {
        return $this->hasOne(BoardingPass::class)->whereNull('voided_at');
    }

    /*
     * "Who did it" on this flight (SR-45): the audit_log row of the coupon's status change, with
     * ->user and ->changed_at, or null. Same as Passenger::checkedInBy() but for one flight.
     */
    public function checkedInBy(): HasOne
    {
        return $this->statusChangeTo(PassengerStatus::CheckedIn);
    }

    public function boardedBy(): HasOne
    {
        return $this->statusChangeTo(PassengerStatus::Boarded);
    }

    private function statusChangeTo(PassengerStatus $status): HasOne
    {
        return $this->hasOne(AuditEntry::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('field', 'status')->where('new_value', $status->value),
        );
    }
}
