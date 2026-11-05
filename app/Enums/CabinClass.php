<?php

namespace App\Enums;

enum CabinClass: string
{
    case Business = 'Business';
    case Premium = 'Premium';
    case Economy = 'Economy';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Premium => 'Premium',
            self::Economy => 'Economy',
        };
    }
}
