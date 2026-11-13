<?php

namespace App\Models;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\PassengerStatus;
use App\Enums\SegmentDirection;
use App\Models\Concerns\StoresTimesInUtc;
use App\Services\JourneyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bag extends Model
{
    use HasFactory, StoresTimesInUtc;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'passenger_id',
        'direction',
        'destination',
        'tag_no',
        'type',
        'handling',
        'weight_kg',
        'over_allowance',
        'offloaded_at',
        'offloaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => SegmentDirection::class,
            'type' => BagType::class,
            'handling' => BagHandling::class,
            'weight_kg' => 'decimal:1',
            'over_allowance' => 'boolean',
            'offloaded_at' => 'datetime',
        ];
    }

    /**
     * A bag goes to the final destination of its direction (B13b, SR-67). BagService sets both;
     * other code (and factories) get the outbound direction and its destination.
     */
    protected static function booted(): void
    {
        static::creating(function (Bag $bag) {
            $bag->direction ??= SegmentDirection::Outbound;

            if ($bag->destination === null) {
                $reservation = Passenger::query()->withoutEagerLoads()->findOrFail($bag->passenger_id)->reservation;
                $bag->destination = app(JourneyService::class)->finalDestination($reservation, $bag->direction);
            }
        });
    }

    /**
     * Bags of passengers who are NoShow on this flight, checked in the direction of that coupon:
     * they must come off the aircraft (SR-9, SR-28). Bags go to the end of their direction (SR-67).
     */
    public function scopeNoShowOn(Builder $query, Flight $flight): void
    {
        $query->whereExists(fn ($coupons) => $coupons
            ->from('coupons')
            ->join('reservation_segments', 'reservation_segments.id', '=', 'coupons.reservation_segment_id')
            ->whereColumn('coupons.passenger_id', 'bags.passenger_id')
            ->whereColumn('reservation_segments.direction', 'bags.direction')
            ->where('coupons.flight_id', $flight->id)
            ->where('coupons.status', PassengerStatus::NoShow->value));
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function offloadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offloaded_by');
    }
}
