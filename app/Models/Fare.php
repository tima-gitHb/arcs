<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use Illuminate\Database\Eloquent\Model;

/**
 * Price of a ticket per class and passenger type, in cents. Read by FareService.
 */
class Fare extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
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
}
