<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by BoardingPassService when no pass may be issued (C5). The message can be shown as-is.
 */
class BoardingPassNotAllowed extends DomainException {}
