<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class FlightSchedule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'flight_no',
        'origin',
        'destination',
        'aircraft_id',
        'days_of_week',
        'departure_local_time',
        'block_minutes',
        'gate',
        'checkin_opens_minutes_before',
        'checkin_closes_minutes_before',
        'valid_from',
        'valid_to',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
            'block_minutes' => 'integer',
            'checkin_opens_minutes_before' => 'integer',
            'checkin_closes_minutes_before' => 'integer',
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

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    /**
     * Departure clock as H:i for forms and lists.
     */
    public function departureHm(): string
    {
        $raw = (string) $this->attributes['departure_local_time'] ?? '';

        return strlen($raw) >= 5 ? substr($raw, 0, 5) : $raw;
    }

    /**
     * Short weekday chips: Mon, Wed, Fri.
     *
     * @return list<string>
     */
    public function dayLabels(): array
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return collect($this->days_of_week ?? [])
            ->map(fn ($day) => $names[(int) $day] ?? (string) $day)
            ->values()
            ->all();
    }

    public function isValidOn(Carbon $localDate): bool
    {
        // Compare calendar dates only — valid_from/to are date columns (UTC midnight),
        // while $localDate is midnight at the origin airport.
        $day = $localDate->toDateString();

        return $day >= $this->valid_from->toDateString()
            && $day <= $this->valid_to->toDateString()
            && in_array((int) $localDate->isoWeekday(), array_map('intval', $this->days_of_week ?? []), true);
    }
}
