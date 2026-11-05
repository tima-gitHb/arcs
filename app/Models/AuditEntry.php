<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEntry extends Model
{
    protected $table = 'audit_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'passenger_id',
        'flight_id',
        'field',
        'old_value',
        'new_value',
        'user_id',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /**
     * An entry about a flight, with no passenger.
     */
    public static function forFlight(Flight $flight, string $field, ?string $old, string $new, User $by): self
    {
        return self::create([
            'flight_id' => $flight->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'user_id' => $by->id,
            'changed_at' => now(),
        ]);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
