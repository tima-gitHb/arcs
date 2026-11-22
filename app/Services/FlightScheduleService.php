<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Support\Limits;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Aircraft schedule overlap / turnaround (#27) and cabin capacity when re-assigning (#28).
 */
class FlightScheduleService
{
    /**
     * Refuse overlapping flights on the same aircraft, including min turnaround after arrival.
     *
     * @param  array{aircraft_id: int, departure_at: Carbon, arrives_at: ?Carbon}  $data
     */
    public function assertNoOverlap(array $data, ?int $ignoreFlightId = null): void
    {
        $turnaround = Limits::minTurnaroundMinutes();
        $start = $data['departure_at']->copy();
        $end = ($data['arrives_at'] ?? $data['departure_at'])->copy();

        $others = Flight::query()
            ->where('aircraft_id', $data['aircraft_id'])
            ->when($ignoreFlightId, fn ($q) => $q->where('id', '!=', $ignoreFlightId))
            ->whereNotIn('status', ['Cancelled'])
            ->get(['id', 'flight_no', 'departure_at', 'arrives_at']);

        foreach ($others as $other) {
            $otherStart = $other->departure_at->copy();
            $otherEnd = ($other->arrives_at ?? $other->departure_at)->copy();

            // Occupied window padded by turnaround after each arrival.
            $blockedUntil = $otherEnd->copy()->addMinutes($turnaround);
            $newBlockedUntil = $end->copy()->addMinutes($turnaround);

            $overlaps = $start->lt($blockedUntil) && $otherStart->lt($newBlockedUntil);

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'aircraft_id' => "Aircraft is busy with {$other->flight_no}: keep at least {$turnaround} minutes turnaround.",
                ]);
            }
        }
    }

    /**
     * Refuse moving a flight onto an aircraft that has fewer seats in a cabin than already sold.
     */
    public function assertAircraftFitsSold(Flight $flight, Aircraft $aircraft): void
    {
        foreach (CabinClass::cases() as $cabin) {
            $sold = SeatAssignment::query()
                ->join('seats', 'seats.id', '=', 'seat_assignments.seat_id')
                ->where('seat_assignments.flight_id', $flight->id)
                ->where('seats.cabin_class', $cabin->value)
                ->count();

            $capacity = Seat::query()
                ->where('aircraft_id', $aircraft->id)
                ->where('cabin_class', $cabin->value)
                ->count();

            if ($sold > $capacity) {
                throw ValidationException::withMessages([
                    'aircraft_id' => "Cannot move to this aircraft: {$sold} {$cabin->label()} seats sold, only {$capacity} available.",
                ]);
            }
        }
    }
}
