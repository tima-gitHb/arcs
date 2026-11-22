<?php

namespace App\Support;

/**
 * Actions that depend on flight status (validation audit #41 / #7).
 */
enum FlightAction: string
{
    case Book = 'book';
    case ChangeSeat = 'change_seat';
    case CheckIn = 'check_in';
    case Board = 'board';
}
