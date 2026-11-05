<?php

namespace App\Models;

use App\Enums\FlightStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Flight extends Model
{
    use HasFactory;

    /**
     * 'status' and 'boarding_closed_at' are deliberately not fillable:
     * they only change through StatusService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'flight_no',
        'origin',
        'destination',
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

    /**
     * Is now() inside the check-in window (both ends included)? SR-30.
     * Time only: StatusService::checkIn() also requires the CheckinOpen status (SR-25).
     */
    public function isCheckinWindowOpen(): bool
    {
        return now()->between($this->checkin_opens_at, $this->checkin_closes_at);
    }

    /**
     * Has boarding been closed (SR-12)? After this, seats can no longer change (SR-20).
     */
    public function isBoardingClosed(): bool
    {
        return in_array($this->status, [FlightStatus::Closed, FlightStatus::Departed], true);
    }

    /**
     * Can a passenger still be checked in late at the gate (SR-29)? Normal check-in is over
     * (flight Boarding, or the window has passed) and boarding is not closed.
     */
    public function isLateCheckInOpen(): bool
    {
        if ($this->isBoardingClosed() || $this->status === FlightStatus::Cancelled) {
            return false;
        }

        return $this->status === FlightStatus::Boarding || now()->gt($this->checkin_closes_at);
    }
}
