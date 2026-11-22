<?php

namespace App\Support;

/**
 * Card number Luhn check (validation #13).
 */
final class Luhn
{
    public static function passes(string $digits): bool
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';

        if (strlen($digits) < 12 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
