<?php

namespace App\Enums;

enum PassengerType: string
{
    case Adult = 'Adult';
    case Child = 'Child';
    case Infant = 'Infant';

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child',
            self::Infant => 'Infant',
        };
    }
}
