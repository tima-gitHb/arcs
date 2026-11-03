<?php

namespace App\Services;

use App\Enums\PassengerType;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seats on a flight: the seat map data shared by reservation (R1) and check-in (C3),
 * and choosing a seat at check-in (SR-7).
 */
class SeatService
{
    public function __construct(private BagService $bags) {}

    /**
     * Every seat of the flight's aircraft, in row order (2A before 10A), with "taken"
     * set when another passenger holds it. Drawn by resources/js/seat-map.js.
     *
     * @return Collection<int, array{id: int, seat_no: string, row: int, letter: string, cabin: string, taken: bool}>
     */
    public function seatMap(Flight $flight): Collection
    {
        $taken = array_flip(
            SeatAssignment::query()->where('flight_id', $flight->id)->pluck('seat_id')->all(),
        );

        return Seat::query()
            ->where('aircraft_id', $flight->aircraft_id)
            ->get()
            ->sortBy(function (Seat $seat): int {
                if (! preg_match('/^(\d+)([A-F])$/', $seat->seat_no, $matches)) {
                    return 9999;
                }

                return ((int) $matches[1]) * 10 + ord($matches[2]);
            })
            ->values()
            ->map(function (Seat $seat) use ($taken): array {
                preg_match('/^(\d+)([A-F])$/', $seat->seat_no, $matches);

                return [
                    'id' => $seat->id,
                    'seat_no' => $seat->seat_no,
                    'row' => isset($matches[1]) ? (int) $matches[1] : 0,
                    'letter' => $matches[2] ?? '',
                    'cabin' => $seat->cabin_class->value,
                    'taken' => isset($taken[$seat->id]),
                ];
            });
    }

    /**
     * Give the passenger this seat, replacing any seat they already had, in one transaction.
     * Two agents picking the same seat: the UNIQUE (flight_id, seat_id) key lets only one
     * insert succeed; the other gets SeatTaken and keeps their old seat (SR-7).
     */
    public function assign(Passenger $passenger, Seat $seat, User $by): void
    {
        DB::transaction(function () use ($passenger, $seat, $by) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            $flight = $passenger->reservation->flight;

            if ($passenger->type === PassengerType::Infant) {
                throw new SeatNotAllowed('An infant has no seat.');
            }
            if (! $passenger->checkinDetailsEditable()) {
                throw new SeatNotAllowed('The seat can only be changed for a booked or checked-in passenger.');
            }
            if ($seat->aircraft_id !== $flight->aircraft_id) {
                throw new SeatNotAllowed('That seat is not on this flight.');
            }

            $current = $passenger->seatAssignment()->with('seat')->first();

            if ($current?->seat_id === $seat->id) {
                return;
            }

            $current?->delete();

            try {
                SeatAssignment::create([
                    'flight_id' => $flight->id,
                    'seat_id' => $seat->id,
                    'passenger_id' => $passenger->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Thrown inside the transaction, so the delete above is rolled back too.
                throw new SeatTaken;
            }

            AuditEntry::create([
                'passenger_id' => $passenger->id,
                'field' => 'seat',
                'old_value' => $current?->seat->seat_no,
                'new_value' => $seat->seat_no,
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);

            // A new cabin can mean a new baggage allowance (C4).
            $this->bags->recalculate($passenger->fresh());
        });
    }
}
