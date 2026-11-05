<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by SpecialServiceService when a service may not be added or removed (C7).
 * The message can be shown as-is.
 */
class SpecialServiceNotAllowed extends DomainException {}
