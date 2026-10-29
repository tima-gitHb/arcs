<?php

namespace App\Enums;

enum BagType: string
{
    case Standard = 'Standard';
    case Wheelchair = 'Wheelchair';
    case Stroller = 'Stroller';
    case SportsEquipment = 'SportsEquipment';
    case MusicalInstrument = 'MusicalInstrument';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard bag',
            self::Wheelchair => 'Wheelchair',
            self::Stroller => 'Stroller',
            self::SportsEquipment => 'Sports equipment',
            self::MusicalInstrument => 'Musical instrument',
        };
    }

    /**
     * Wheelchairs and strollers travel free: they are not counted against
     * the passenger's baggage allowance (SR-43).
     */
    public function countsTowardAllowance(): bool
    {
        return match ($this) {
            self::Wheelchair, self::Stroller => false,
            default => true,
        };
    }
}
