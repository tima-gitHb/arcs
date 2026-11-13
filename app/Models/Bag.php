<?php

namespace App\Models;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Models\Concerns\StoresTimesInUtc;
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
            'type' => BagType::class,
            'handling' => BagHandling::class,
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
