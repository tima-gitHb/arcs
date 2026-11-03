<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by BagService when a bag may not be added or removed for this passenger (C4).
 * The message can be shown as-is.
 */
class BagNotAllowed extends DomainException {}
