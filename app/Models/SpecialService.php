<?php

namespace App\Models;

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
    ];

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }
}
