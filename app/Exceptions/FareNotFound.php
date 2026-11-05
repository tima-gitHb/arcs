<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by FareService when a price is missing from the fares or fees table.
 * The message can be shown as-is.
 */
class FareNotFound extends DomainException {}
