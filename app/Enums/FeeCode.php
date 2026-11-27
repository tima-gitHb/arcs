<?php

namespace App\Enums;

enum FeeCode: string
{
    case ExtraBag = 'ExtraBag';
    case ExcessKg = 'ExcessKg'; // per kg
    case SportsEquipment = 'SportsEquipment';
    case Pet = 'Pet';
    case Cancellation = 'Cancellation'; // per passenger per leg
    case Change = 'Change'; // per passenger per leg

    public function label(): string
    {
        return match ($this) {
            self::ExtraBag => 'Extra bag',
            self::ExcessKg => 'Excess baggage (per kg)',
            self::SportsEquipment => 'Sports equipment',
            self::Pet => 'Pet',
            self::Cancellation => 'Cancellation',
            self::Change => 'Change',
        };
    }
}
