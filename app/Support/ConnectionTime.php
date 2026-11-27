<?php

namespace App\Support;

use App\Models\Flight;

/**
 * Human label for the gap between two connecting flights (R13 / C20).
 * Format: "45 min" under one hour; "1 h 05 min" / "2 h 10 min" when hours are shown.
 */
final class ConnectionTime
{
    public const ERROR_LABEL = 'invalid connection';

    /**
     * Gap in whole minutes from arrival to next departure, or null when times are missing.
     */
    public static function minutes(Flight $previous, Flight $next): ?int
    {
        if ($previous->arrives_at === null || $next->departure_at === null) {
            return null;
        }

        return (int) $previous->arrives_at->diffInMinutes($next->departure_at, false);
    }

    /**
     * Display label. Negative gaps use {@see ERROR_LABEL}. Missing times → null.
     */
    public static function label(Flight $previous, Flight $next): ?string
    {
        $minutes = self::minutes($previous, $next);

        if ($minutes === null) {
            return null;
        }

        if ($minutes < 0) {
            return self::ERROR_LABEL;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours > 0 && $rest > 0) {
            return $hours.' h '.str_pad((string) $rest, 2, '0', STR_PAD_LEFT).' min';
        }

        if ($hours > 0) {
            return $hours.' h';
        }

        return $rest.' min';
    }

    /**
     * True when the gap is below the connecting airport's minimum connection time.
     */
    public static function isTight(Flight $previous, Flight $next): bool
    {
        $minutes = self::minutes($previous, $next);

        if ($minutes === null || $minutes < 0) {
            return false;
        }

        $minimum = (int) ($previous->destinationAirport?->min_connection_minutes
            ?? $previous->loadMissing('destinationAirport')->destinationAirport?->min_connection_minutes
            ?? 60);

        return $minutes < $minimum;
    }
}
