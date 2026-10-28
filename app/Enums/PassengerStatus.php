<?php

namespace App\Enums;

enum PassengerStatus: string
{
    case Booked = 'Booked';
    case CheckedIn = 'CheckedIn';
    case Boarded = 'Boarded';
    case Cancelled = 'Cancelled';
    case NoShow = 'NoShow';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::CheckedIn => 'Checked in',
            self::Boarded => 'Boarded',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }
}
