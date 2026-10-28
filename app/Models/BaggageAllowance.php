<?php

namespace App\Models;

use App\Enums\CabinClass;
use Illuminate\Database\Eloquent\Model;

class BaggageAllowance extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'cabin_class',
        'pieces',
        'max_kg_per_bag',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'pieces' => 'integer',
            'max_kg_per_bag' => 'decimal:1',
        ];
    }
}
