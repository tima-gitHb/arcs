<?php

namespace App\Enums;

enum CabinClass: string
{
    case Business = 'Business';
    case Economy = 'Economy';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Economy => 'Economy',
        };
    }
}
