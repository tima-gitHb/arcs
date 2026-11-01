<?php

namespace App\Exceptions;

use DomainException;

/**
 * Two passengers cannot hold the same seat on a flight (SR-7).
 */
class SeatTaken extends DomainException
{
    public function __construct()
    {
        parent::__construct('Seat taken');
    }
}
