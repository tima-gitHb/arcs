<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'passenger_id',
        'tag_no',
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
            'weight_kg' => 'decimal:1',
            'over_allowance' => 'boolean',
            'offloaded_at' => 'datetime',
        ];
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
