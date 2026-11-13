<?php

namespace App\Support;

use App\Models\Airport;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Times are stored in UTC and shown in local time (B13a). In views use the components:
 *     <x-local-time :at="$flight->departure_at" :airport="$flight->origin" />  a flight time, at an airport
 *     <x-station-time :at="$entry->changed_at" />                               when something happened, at the viewer's station
 * This class is what they use, and what PHP code (mail, services) uses directly.
 */
final class LocalTime
{
    /**
     * The same instant in an airport's time zone (UTC for no airport). A copy: the model's value is untouched.
     */
    public static function at(?DateTimeInterface $at, ?string $airport): ?Carbon
    {
        return $at === null ? null : Carbon::instance($at)->setTimezone(Airport::timezoneOf($airport));
    }

    /**
     * One calendar day in a time zone, as the two UTC instants that bound it, for a whereBetween()
     * on a UTC column. $date is Y-m-d; null = today in that zone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayInUtc(string $timezone, ?string $date = null): array
    {
        $day = $date === null ? now($timezone) : Carbon::parse($date, $timezone);

        return [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()];
    }
}
