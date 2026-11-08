<?php

namespace App\Exceptions;

use DomainException;

/**
 * The card was refused. The booking must not be kept.
 */
class PaymentDeclined extends DomainException
{
    public function __construct(string $message = 'Card declined.')
    {
        parent::__construct($message);
    }
}
