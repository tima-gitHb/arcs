<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Journey origin → final destination price (B14, CR-13/CR-14). Read by FareService.
 */
class TripFare extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'origin_code',
        'destination_code',
        'cabin_class',
        'passenger_type',
        'one_way_cents',
        'round_trip_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'passenger_type' => PassengerType::class,
            'one_way_cents' => 'integer',
            'round_trip_cents' => 'integer',
        ];
    }

    public function scopeForTrip(Builder $query, string $origin, string $destination): void
    {
        $query->where('origin_code', $origin)->where('destination_code', $destination);
    }
}
