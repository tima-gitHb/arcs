<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by ManifestService when a PNR typed at the gate cannot be boarded on this flight
 * (unknown, booked on another flight, wrong passenger). The message can be shown as-is.
 */
class PnrNotOnFlight extends DomainException {}
