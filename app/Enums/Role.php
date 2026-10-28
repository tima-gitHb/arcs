<?php

namespace App\Enums;

enum Role: string
{
    case Reservation = 'reservation';
    case Checkin = 'checkin';
    case Gate = 'gate';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => 'Reservation staff',
            self::Checkin => 'Check-in staff',
            self::Gate => 'Gate staff',
            self::Manager => 'Manager',
        };
    }

    /**
     * Name of the route each role lands on after login (SR-13).
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Reservation => 'reservation.home',
            self::Checkin => 'checkin.home',
            self::Gate => 'gate.home',
            self::Manager => 'manager.home',
        };
    }
}
