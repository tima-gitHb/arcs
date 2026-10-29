<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by StatusService when a status change breaks a rule.
 * The message is written for staff and can be shown as-is (e.g. "Check-in closed").
 */
class InvalidStatusChange extends DomainException
{
    public function __construct(string $message = 'This status change is not allowed.')
    {
        parent::__construct($message);
    }
}
