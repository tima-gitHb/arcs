<?php

namespace App\Services;

use App\Models\Reservation;
use RuntimeException;

/**
 * Generates booking references (SR-1): 6 characters, unique in reservations.
 * 0/O and 1/I are left out so staff cannot misread them.
 */
class PnrGenerator
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    private const MAX_ATTEMPTS = 10;

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $pnr = $this->randomCode();

            if (! Reservation::where('pnr', $pnr)->exists()) {
                return $pnr;
            }
        }

        throw new RuntimeException('Could not generate a unique PNR.');
    }

    protected function randomCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
