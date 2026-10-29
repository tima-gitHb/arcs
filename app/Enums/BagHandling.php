<?php

namespace App\Enums;

enum BagHandling: string
{
    case Hold = 'Hold';
    case Cabin = 'Cabin';
    case GateDelivery = 'GateDelivery';

    public function label(): string
    {
        return match ($this) {
            self::Hold => 'Hold',
            self::Cabin => 'Cabin',
            self::GateDelivery => 'Gate delivery',
        };
    }
}
