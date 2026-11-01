<?php

namespace App\Services;

use App\Enums\PassengerType;
use App\Exceptions\SeatTaken;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a reservation and its passengers together (SR-1, SR-4, SR-24).
 */
class ReservationService
{
    public function __construct(private PnrGenerator $pnrs) {}

    /**
     * Passenger status is left to the database default (Booked). Infants are
     * linked to an adult and are not given a seat.
     *
     * @param  array<int, array{first_name: string, last_name: string, type: string, date_of_birth: string, accompanying_adult?: int|string|null}>  $passengers
     */
    public function create(Flight $flight, User $agent, array $passengers): Reservation
    {
        $this->guardInfants($passengers);

        return DB::transaction(function () use ($flight, $agent, $passengers) {
            $reservation = Reservation::create([
                'pnr' => $this->pnrs->generate(),
                'flight_id' => $flight->id,
                'created_by' => $agent->id,
            ]);

            $created = [];

            foreach ($passengers as $index => $row) {
                $created[$index] = $reservation->passengers()->create([
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'type' => $row['type'],
                    'date_of_birth' => $row['date_of_birth'],
                ]);
            }

            foreach ($passengers as $index => $row) {
                if (PassengerType::from($row['type']) !== PassengerType::Infant) {
                    continue;
                }

                $adult = $created[(int) $row['accompanying_adult']];
                $created[$index]->update([
                    'accompanying_adult_id' => $adult->id,
                ]);
            }

            $this->assignSeats($flight, $passengers, $created);

            return $reservation->load('passengers');
        });
    }

    /**
     * Adults and children get the seat they picked. Infants never do.
     * The unique key on (flight_id, seat_id) is the backstop if two staff
     * choose the same seat at the same moment (SR-7).
     *
     * @param  array<int, array<string, mixed>>  $passengers
     * @param  array<int, Passenger>  $created
     */
    private function assignSeats(Flight $flight, array $passengers, array $created): void
    {
        $wanted = [];

        foreach ($passengers as $index => $row) {
            if (PassengerType::from($row['type']) === PassengerType::Infant) {
                continue;
            }

            $seatId = $row['seat_id'] ?? null;

            if ($seatId === null || $seatId === '') {
                continue;
            }

            $seatId = (int) $seatId;

            if (isset($wanted[$seatId])) {
                throw new SeatTaken;
            }

            $wanted[$seatId] = $index;
        }

        if ($wanted === []) {
            return;
        }

        $onThisAircraft = Seat::query()
            ->where('aircraft_id', $flight->aircraft_id)
            ->whereIn('id', array_keys($wanted))
            ->count();

        if ($onThisAircraft !== count($wanted)) {
            throw new SeatTaken;
        }

        $alreadyTaken = SeatAssignment::query()
            ->where('flight_id', $flight->id)
            ->whereIn('seat_id', array_keys($wanted))
            ->lockForUpdate()
            ->exists();

        if ($alreadyTaken) {
            throw new SeatTaken;
        }

        try {
            foreach ($wanted as $seatId => $index) {
                SeatAssignment::create([
                    'flight_id' => $flight->id,
                    'seat_id' => $seatId,
                    'passenger_id' => $created[$index]->id,
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            throw new SeatTaken;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $passengers
     */
    private function guardInfants(array $passengers): void
    {
        foreach ($passengers as $index => $row) {
            if (PassengerType::from($row['type']) !== PassengerType::Infant) {
                continue;
            }

            $adultIndex = $row['accompanying_adult'] ?? null;
            $adult = is_numeric($adultIndex) ? ($passengers[(int) $adultIndex] ?? null) : null;
            $linkedToAdult = is_array($adult)
                && (int) $adultIndex !== (int) $index
                && PassengerType::from($adult['type']) === PassengerType::Adult;

            if (! $linkedToAdult) {
                throw new InvalidArgumentException('An infant must be linked to an adult on the same reservation.');
            }
        }
    }
}
