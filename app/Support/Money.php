<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is always integer cents. Use this wherever an amount is shown.
 */
class Money
{
    /**
     * 123400 → "$1,234.00".
     */
    public static function format(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    /**
     * "19.99" → 1999, "250" → 25000. The opposite of format(), for amounts typed in dollars.
     * Works on the text, never on a float (19.99 * 100 is 1998.9999... as a float).
     * Accepts digits with at most 2 decimals; anything else throws InvalidArgumentException.
     */
    public static function toCents(string $dollars): int
    {
        // /D: the whole text must match, not even a trailing newline is allowed.
        // At most 15 digits before the dot, so the result always fits in an integer.
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,2}))?$/D', $dollars, $parts)) {
            throw new InvalidArgumentException("Not an amount in dollars: \"{$dollars}\".");
        }

        // "5" → 5 x 100 + 0. "0.5" → 0 x 100 + 50, because the decimals are padded to two digits ("5" → "50").
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }
}
