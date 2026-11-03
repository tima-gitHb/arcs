<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by SeatService when a seat may not be given to this passenger
 * (infant, seat not on the flight's aircraft, passenger no longer Booked/CheckedIn).
 * The message can be shown as-is. A seat held by someone else is SeatTaken instead.
 */
class SeatNotAllowed extends DomainException {}
