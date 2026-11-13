<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatAssignment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'flight_id',
        'seat_id',
        'passenger_id',
        'coupon_id',
    ];

    /**
     * Code written before B13b (and many tests) gives passenger + flight only: the coupon is the
     * passenger's coupon on that flight. New code passes coupon_id itself.
     */
    protected static function booted(): void
    {
        static::saving(function (SeatAssignment $assignment) {
            if ($assignment->coupon_id === null || $assignment->isDirty(['passenger_id', 'flight_id'])) {
                $assignment->coupon_id = Coupon::query()
                    ->where('passenger_id', $assignment->passenger_id)
                    ->where('flight_id', $assignment->flight_id)
                    ->value('id') ?? $assignment->coupon_id;
            }
        });
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }
}
