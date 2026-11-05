<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by LateCheckInService when a Manager's on-the-spot approval is not valid (SR-29).
 * The message can be shown as-is.
 */
class ManagerApprovalRefused extends DomainException {}
