<?php

namespace App\Models;

use App\Enums\FlightStatus;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\LocalTime;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Flight extends Model
{
    use HasFactory, StoresTimesInUtc;

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
        'arrives_at',
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
            'arrives_at' => 'datetime',
            'checkin_opens_at' => 'datetime',
            'checkin_closes_at' => 'datetime',
            'boarding_closed_at' => 'datetime',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin', 'code');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination', 'code');
    }

    /**
     * Flights leaving this airport (B13a). Check-in and gate staff only work their station's
     * departures; the Manager's station filter (M17) builds on this.
     */
    public function scopeDepartingFrom(Builder $query, string $airportCode): void
    {
        $query->where('flights.origin', $airportCode);
    }

    /**
     * Flights departing on the user's "today": the calendar day at their station (UTC without one).
     * The day only: add departingFrom() for the station's own flights.
     */
    public function scopeDepartingToday(Builder $query, User $user): void
    {
        $query->whereBetween('flights.departure_at', $user->stationDay());
    }

    /**
     * Departure in the origin airport's local time, for display (B13a). Comparisons use departure_at (UTC).
     */
    public function departureLocal(): Carbon
    {
        return LocalTime::at($this->departure_at, $this->origin);
    }

    /**
     * The calendar date of departure at the origin airport, as a date (midnight UTC, like the
     * 'date' casts), to compare with document expiry dates (SR-23). Not the UTC date: a 01:00
     * Beirut departure is still the day before in UTC.
     */
    public function departureDate(): Carbon
    {
        return Carbon::parse($this->departureLocal()->toDateString(), 'UTC');
    }

    /**
     * Arrival in the destination airport's local time, for display (B13b); null when not set.
     */
    public function arrivalLocal(): ?Carbon
    {
        return LocalTime::at($this->arrives_at, $this->destination);
    }

    /**
     * Any time of this flight (check-in window, boarding) in the origin airport's local time, for display.
     */
    public function atOrigin(?DateTimeInterface $time): ?Carbon
    {
        return LocalTime::at($time, $this->origin);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class);
    }

    /**
     * Everyone with a coupon on this flight (B13b): the passengers of its reservations and those
     * connecting onto it from another flight.
     */
    public function passengers(): BelongsToMany
    {
        return $this->belongsToMany(Passenger::class, 'coupons');
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
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
