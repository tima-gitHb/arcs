<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by CorrectionService when a passenger's travel documents may not be changed
 * any more (only Booked and CheckedIn passengers, C2). The message can be shown as-is.
 */
class DocumentsLocked extends DomainException
{
    public function __construct(string $message = 'Documents can only be changed for a booked or checked-in passenger.')
    {
        parent::__construct($message);
    }
}
