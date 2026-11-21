<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Rule limits (B14, CR-15). The only place to read these settings; never hard-code the values elsewhere.
 */
final class Limits
{
    public static function childAloneMinAge(): int
    {
        return self::int('child_alone_min_age', 16);
    }

    public static function umnrMinAge(): int
    {
        return self::int('umnr_min_age', 5);
    }

    public static function maxInfantsPerAdult(): int
    {
        return self::int('max_infants_per_adult', 1);
    }

    public static function maxPassengersPerBooking(): int
    {
        return self::int('max_passengers_per_booking', 9);
    }

    public static function maxBagKg(): int
    {
        return self::int('max_bag_kg', 32);
    }

    public static function maxAircraftSeats(): int
    {
        return self::int('max_aircraft_seats', 220);
    }

    public static function minTurnaroundMinutes(): int
    {
        return self::int('min_turnaround_minutes', 45);
    }

    public static function maxConnectionHours(): int
    {
        return self::int('max_connection_hours', 24);
    }

    public static function maxPetsInCabin(): int
    {
        return self::int('max_pets_in_cabin', 2);
    }

    public static function maxLegs(): int
    {
        return self::int('max_legs', 6);
    }

    private static function int(string $key, int $default): int
    {
        $value = Setting::find($key)?->value;

        return $value === null || $value === '' ? $default : (int) $value;
    }
}
