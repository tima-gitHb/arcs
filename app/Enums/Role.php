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

    /**
     * Side menu items for this role, as [label => route name].
     *
     * @return array<string, string>
     */
    public function menu(): array
    {
        return match ($this) {
            self::Reservation => [
                'New reservation' => 'reservation.create',
                'Find reservation' => 'reservation.search',
            ],
            self::Checkin => [
                'Check in passenger' => 'checkin.home',
            ],
            self::Gate => [
                'Manifest' => 'gate.home',
            ],
            self::Manager => [
                'Flight lists' => 'manager.home',
                'Dashboard' => 'manager.dashboard',
                'Flights and aircraft' => 'manager.flights',
                'Staff accounts' => 'manager.accounts',
                'Rules and allowances' => 'manager.rules',
                'Fares and fees' => 'manager.fares',
                'Audit log' => 'manager.audit',
            ],
        };
    }
}
