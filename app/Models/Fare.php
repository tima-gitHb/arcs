<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Price of a ticket per class and passenger type, in cents. Read by FareService.
 * No origin and no destination = the default fare; with a route it replaces the default on that
 * route (B13b, SR-69). Pages that show "the" fares (M7) read defaults() only.
 */
class Fare extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'origin_airport_code',
        'destination_airport_code',
        'cabin_class',
        'passenger_type',
        'amount_cents',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'passenger_type' => PassengerType::class,
            'amount_cents' => 'integer',
        ];
    }

    /**
     * The default fares (no route).
     */
    public function scopeDefaults(Builder $query): void
    {
        $query->whereNull('origin_airport_code')->whereNull('destination_airport_code');
    }

    /**
     * The fares of one route.
     */
    public function scopeForRoute(Builder $query, string $origin, string $destination): void
    {
        $query->where('origin_airport_code', $origin)->where('destination_airport_code', $destination);
    }
}
