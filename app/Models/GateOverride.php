<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateOverride extends Model
{
    use StoresTimesInUtc;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'passenger_id',
        'coupon_id',
        'approved_by',
        'reason',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
