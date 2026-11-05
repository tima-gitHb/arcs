<?php

namespace App\Support;

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
}
