<?php

namespace App\Support;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Models\Flight;

/**
 * One central check: which actions a flight status allows (validation #41 / #7).
 * Scheduled / Delayed = book + seat change. Check-in open = those + check-in.
 * Boarding = boarding only. Closed / Departed / Cancelled = nothing.
 */
final class FlightStatusGate
{
    public static function allows(Flight $flight, FlightAction $action): bool
    {
        $status = $flight->status ?? FlightStatus::Scheduled;

        return match ($action) {
            FlightAction::Book, FlightAction::ChangeSeat => in_array($status, [
                FlightStatus::Scheduled,
                FlightStatus::CheckinOpen,
                FlightStatus::Delayed,
            ], true),
            // Refunds / booking changes: only before boarding (Sprint 4 Feature 2).
            FlightAction::Refund => in_array($status, [
                FlightStatus::Scheduled,
                FlightStatus::CheckinOpen,
            ], true),
            FlightAction::CheckIn => $status === FlightStatus::CheckinOpen,
            FlightAction::Board => $status === FlightStatus::Boarding,
        };
    }

    /**
     * @throws InvalidStatusChange
     */
    public static function assertAllows(Flight $flight, FlightAction $action): void
    {
        if (self::allows($flight, $action)) {
            return;
        }

        $label = $flight->status->label();

        $message = match ($action) {
            FlightAction::Book => "{$flight->flight_no} is {$label}: it cannot be booked.",
            FlightAction::ChangeSeat => "{$flight->flight_no} is {$label}: the seat cannot be changed.",
            FlightAction::Refund => "{$flight->flight_no} is {$label}: it cannot be refunded.",
            FlightAction::CheckIn => 'Check-in closed',
            FlightAction::Board => 'Boarding has not started',
        };

        throw new InvalidStatusChange($message);
    }

    /**
     * Statuses that may appear on the new-reservation flight list.
     *
     * @return list<FlightStatus>
     */
    public static function bookableStatuses(): array
    {
        return [
            FlightStatus::Scheduled,
            FlightStatus::CheckinOpen,
            FlightStatus::Delayed,
        ];
    }
}
