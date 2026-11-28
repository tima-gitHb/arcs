<?php

namespace App\Enums;

enum PassengerStatus: string
{
    case Booked = 'Booked';
    case CheckedIn = 'CheckedIn';
    case Boarded = 'Boarded';
    case Cancelled = 'Cancelled';
    case NoShow = 'NoShow';
    case Exchanged = 'Exchanged';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::CheckedIn => 'Checked in',
            self::Boarded => 'Boarded',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
            self::Exchanged => 'Exchanged',
        };
    }

    /** Final coupon states that never appear on check-in / gate lists. */
    public function isInactiveOnFlight(): bool
    {
        return in_array($this, [self::Cancelled, self::Exchanged], true);
    }
}
