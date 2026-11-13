<?php

namespace App\Enums;

/**
 * The two halves of a journey (B13b, SR-65). A one-way or connecting journey is all Outbound;
 * a round trip adds a Return. Check-in covers one direction at a time (SR-66).
 */
enum SegmentDirection: string
{
    case Outbound = 'Outbound';
    case Return = 'Return';

    public function label(): string
    {
        return $this->value;
    }
}
