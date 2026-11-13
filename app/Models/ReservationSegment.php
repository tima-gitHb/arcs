<?php

namespace App\Models;

use App\Enums\SegmentDirection;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One flight of a reservation's journey (B13b). Created by JourneyService (and, for the first flight,
 * when the reservation is created), never by a controller.
 */
class ReservationSegment extends Model
{
    use StoresTimesInUtc;

    /**
     * @var list<string>
     */
    protected $fillable = ['reservation_id', 'flight_id', 'sequence', 'direction'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'direction' => SegmentDirection::class,
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
