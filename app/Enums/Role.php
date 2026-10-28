<?php

namespace App\Enums;

enum Role: string
{
    case Reservation = 'reservation';
    case Checkin = 'checkin';
    case Gate = 'gate';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => 'Reservation staff',
            self::Checkin => 'Check-in staff',
            self::Gate => 'Gate staff',
            self::Manager => 'Manager',
        };
    }
}
