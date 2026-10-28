<?php

namespace App\Models;

use App\Enums\FlightStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Flight extends Model
{
    /**
     * 'status' and 'boarding_closed_at' are deliberately not fillable:
     * they only change through StatusService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'flight_no',
        'aircraft_id',
        'departure_at',
        'gate',
        'checkin_opens_at',
        'checkin_closes_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FlightStatus::class,
            'departure_at' => 'datetime',
            'checkin_opens_at' => 'datetime',
            'checkin_closes_at' => 'datetime',
            'boarding_closed_at' => 'datetime',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class);
    }

    public function passengers(): HasManyThrough
    {
        return $this->hasManyThrough(Passenger::class, Reservation::class);
    }
}
