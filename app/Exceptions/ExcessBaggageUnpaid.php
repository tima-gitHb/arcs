<?php

namespace App\Exceptions;

use DomainException;

/**
 * Confirm / late check-in while pooled excess has no succeeded payment (C10 / SR-59).
 */
class ExcessBaggageUnpaid extends DomainException
{
    public function __construct()
    {
        parent::__construct('Excess baggage not paid');
    }
}
