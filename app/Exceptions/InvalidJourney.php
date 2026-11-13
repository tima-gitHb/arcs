<?php

namespace App\Exceptions;

use DomainException;

/**
 * A journey that breaks a rule of JourneyService (SR-65). The message is shown to staff as is.
 */
class InvalidJourney extends DomainException {}
