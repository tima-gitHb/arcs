<?php

namespace App\Support;

use App\Models\Flight;

/**
 * Human label for the gap between two connecting flights (R13).
 */
final class ConnectionTime
{
    public static function label(Flight $previous, Flight $next): ?string
    {
        if ($previous->arrives_at === null || $next->departure_at === null) {
            return null;
        }

        $minutes = (int) $previous->arrives_at->diffInMinutes($next->departure_at, false);

        if ($minutes < 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours > 0 && $rest > 0) {
            return $hours.' h '.$rest.' min';
        }

        if ($hours > 0) {
            return $hours.' h';
        }

        return $rest.' min';
    }
}
