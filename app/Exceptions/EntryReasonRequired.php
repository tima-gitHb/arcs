<?php

namespace App\Exceptions;

use DomainException;

/**
 * Check-in with a missing or refused entry result needs a written reason (SR-49).
 * Controllers turn this into a validation error on the entry_reason field.
 */
class EntryReasonRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct('Write a reason before confirming: the entry check found a problem.');
    }
}
