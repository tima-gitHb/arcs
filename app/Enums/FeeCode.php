<?php

namespace App\Enums;

enum FeeCode: string
{
    case ExtraBag = 'ExtraBag';
    case ExcessKg = 'ExcessKg'; // per kg
    case SportsEquipment = 'SportsEquipment';
    case Pet = 'Pet';

    public function label(): string
    {
        return match ($this) {
            self::ExtraBag => 'Extra bag',
            self::ExcessKg => 'Excess baggage (per kg)',
            self::SportsEquipment => 'Sports equipment',
            self::Pet => 'Pet',
        };
    }
}
