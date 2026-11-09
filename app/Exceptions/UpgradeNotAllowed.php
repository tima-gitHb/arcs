<?php

namespace App\Exceptions;

use DomainException;

/**
 * Cabin upgrade refused (check-in paid upgrade).
 */
class UpgradeNotAllowed extends DomainException {}
