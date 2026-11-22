<?php

namespace App\Support;

/**
 * Exit / extra-legroom rows (mirrors resources/js/seat-map.js EXIT_ROWS).
 * No DB column: row number is parsed from seat_no.
 */
final class ExitRows
{
    /** @var list<int> */
    public const ROWS = [14, 15];

    public static function contains(int $row): bool
    {
        return in_array($row, self::ROWS, true);
    }

    public static function seatIsExit(string $seatNo): bool
    {
        if (! preg_match('/^(\d+)/', $seatNo, $matches)) {
            return false;
        }

        return self::contains((int) $matches[1]);
    }
}
