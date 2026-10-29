<?php

namespace App\Models;

use App\Enums\SpecialServiceCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialService extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'passenger_id',
        'type',
        'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SpecialServiceCode::class,
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }
}
