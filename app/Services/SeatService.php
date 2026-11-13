<?php

namespace App\Services;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Models\AuditEntry;
use App\Models\Coupon;
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
 * choosing a seat at check-in (SR-7) and changing it afterwards (SR-20).
 * Since B13b a seat belongs to one coupon (one flight of the journey). $flight = null means the
 * first flight of the direction the agent works (JourneyService::firstFlightFor), as before B13b
 * for a one-flight reservation.
 */
class SeatService
{
    public function __construct(
        private BagService $bags,
        private BoardingPassService $passes,
        private JourneyService $journeys,
    ) {}

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
     * Give a Booked passenger this seat, replacing any seat they already had, in one transaction.
     * Two agents picking the same seat: the UNIQUE (flight_id, seat_id) key lets only one
     * insert succeed; the other gets SeatTaken and keeps their old seat (SR-7).
     * A checked-in passenger goes through change() instead, with its extra rules.
     */
    public function assign(Passenger $passenger, Seat $seat, User $by, ?Flight $flight = null): void
    {
        DB::transaction(function () use ($passenger, $seat, $by, $flight) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            $coupon = $this->couponFor($passenger, $by, $flight);

            if ($passenger->type === PassengerType::Infant) {
                throw new SeatNotAllowed('An infant has no seat.');
            }
            if ($coupon->status === PassengerStatus::CheckedIn) {
                throw new SeatNotAllowed('Use Change seat for a checked-in passenger.');
            }
            if ($coupon->status !== PassengerStatus::Booked) {
                throw new SeatNotAllowed('The seat can only be changed for a booked or checked-in passenger.');
            }

            $this->replaceSeat($passenger, $coupon, $seat, $by);
        });
    }

    /**
     * Move a passenger to a seat in a new cabin class during a paid upgrade.
     * Does not issue a boarding pass — CabinUpgradeService does that when CheckedIn.
     */
    public function assignForUpgrade(Passenger $passenger, Seat $seat, User $by, ?Flight $flight = null): void
    {
        DB::transaction(function () use ($passenger, $seat, $by, $flight) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            $coupon = $this->couponFor($passenger, $by, $flight);

            if ($passenger->type === PassengerType::Infant) {
                throw new SeatNotAllowed('An infant has no seat.');
            }
            if (! in_array($coupon->status, [PassengerStatus::Booked, PassengerStatus::CheckedIn], true)) {
                throw new SeatNotAllowed('The seat can only be changed for a booked or checked-in passenger.');
            }

            $this->replaceSeat($passenger, $coupon, $seat, $by);
        });
    }

    /**
     * Move a checked-in passenger to another seat in the same cabin (C6, SR-20).
     * Same swap as assign(); the old pass is voided and a new one issued in the same
     * transaction, so a refused move leaves the old seat and the old pass untouched.
     */
    public function change(Passenger $passenger, Seat $seat, User $by, ?Flight $flight = null): void
    {
        DB::transaction(function () use ($passenger, $seat, $by, $flight) {
            $passenger = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
            $coupon = $this->couponFor($passenger, $by, $flight);

            if ($passenger->type === PassengerType::Infant) {
                throw new SeatNotAllowed('An infant has no seat.');
            }
            $flight = $coupon->flight;

            if ($flight->status === FlightStatus::Cancelled) {
                throw new SeatNotAllowed('This flight is cancelled.');
            }
            if ($flight->isBoardingClosed()) {
                throw new SeatNotAllowed('Boarding is closed for this flight.');
            }
            if ($coupon->status !== PassengerStatus::CheckedIn) {
                throw new SeatNotAllowed('The seat can only be changed for a checked-in passenger.');
            }

            $current = $coupon->seatAssignment?->seat;

            if ($current?->is($seat)) {
                throw new SeatNotAllowed("That is already the passenger's seat.");
            }
            if ($current && $current->cabin_class !== $seat->cabin_class) {
                throw new SeatNotAllowed('Different cabin class');
            }

            $this->replaceSeat($passenger, $coupon, $seat, $by);
            $this->passes->issue($passenger, $by, $flight);
        });
    }

    /**
     * The passenger's coupon on $flight (default: the first flight of the agent's direction), with its flight.
     */
    private function couponFor(Passenger $passenger, User $by, ?Flight $flight): Coupon
    {
        $flight ??= $this->journeys->firstFlightFor($passenger, $by);
        $coupon = $passenger->coupons()->where('coupons.flight_id', $flight->id)->first();

        if ($coupon === null) {
            throw new SeatNotAllowed('The passenger is not booked on this flight.');
        }

        return $coupon->setRelation('flight', $flight);
    }

    /**
     * The swap shared by assign() and change(). Call it inside a transaction: on SeatTaken
     * the rollback puts the old seat back. Choosing the current seat changes nothing.
     */
    private function replaceSeat(Passenger $passenger, Coupon $coupon, Seat $seat, User $by): void
    {
        $flight = $coupon->flight;

        if ($seat->aircraft_id !== $flight->aircraft_id) {
            throw new SeatNotAllowed('That seat is not on this flight.');
        }

        $current = SeatAssignment::query()->where('coupon_id', $coupon->id)->with('seat')->first();

        if ($current?->seat_id === $seat->id) {
            return;
        }

        $current?->delete();

        try {
            SeatAssignment::create([
                'flight_id' => $flight->id,
                'seat_id' => $seat->id,
                'passenger_id' => $passenger->id,
                'coupon_id' => $coupon->id,
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
    }
}
