<?php

namespace App\Enums;

enum FlightStatus: string
{
    case Scheduled = 'Scheduled';
    case CheckinOpen = 'CheckinOpen';
    case Boarding = 'Boarding';
    case Closed = 'Closed';
    case Departed = 'Departed';
    case Delayed = 'Delayed';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::CheckinOpen => 'Check-in open',
            self::Boarding => 'Boarding',
            self::Closed => 'Closed',
            self::Departed => 'Departed',
            self::Delayed => 'Delayed',
            self::Cancelled => 'Cancelled',
        };
    }
}
