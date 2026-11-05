<?php

namespace App\Models;

use App\Enums\FeeCode;
use Illuminate\Database\Eloquent\Model;

/**
 * Price of an extra, in cents. Read by FareService.
 */
class Fee extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'amount_cents',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => FeeCode::class,
            'amount_cents' => 'integer',
        ];
    }
}
