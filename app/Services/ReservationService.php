<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\DocumentType;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\Payments\CardDetails;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a reservation and its passengers together (SR-1, SR-4, SR-24).
 * Each passenger's passport is saved through CorrectionService so check-in
 * can see it and the change is audited (R6). When a card is given, the fare
 * (and extras) are charged first: a declined card rolls the booking back (R9).
 */
class ReservationService
{
    public function __construct(
        private PnrGenerator $pnrs,
        private CorrectionService $corrections,
        private FareService $fares,
        private PaymentService $payments,
    ) {}

    /**
     * Passenger status is left to the database default (Booked). Infants are
     * linked to an adult and are not given a seat.
     *
     * @param  array<int, array{first_name: string, last_name: string, type: string, date_of_birth: string, cabin_class?: string|null, accompanying_adult?: int|string|null, passport: array{number: string, issuing_country: string, expiry_date: string}, seat_id?: int|string|null, extra_bags?: int|string|null, extra_kg?: int|string|null}>  $passengers
     * @param  array{contact_email?: string|null, contact_phone?: string|null}  $contact
     */
    public function create(
        Flight $flight,
        User $agent,
        array $passengers,
        array $contact = [],
        ?CardDetails $card = null,
    ): Reservation {
        $this->guardInfants($passengers);

        return DB::transaction(function () use ($flight, $agent, $passengers, $contact, $card) {
            $reservation = Reservation::create([
                'pnr' => $this->pnrs->generate(),
                'flight_id' => $flight->id,
                'created_by' => $agent->id,
                'contact_email' => $contact['contact_email'] ?? null,
                'contact_phone' => $contact['contact_phone'] ?? null,
            ]);

            $created = [];

            foreach ($passengers as $index => $row) {
                $type = PassengerType::from($row['type']);
                $created[$index] = $reservation->passengers()->create([
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'type' => $row['type'],
                    'date_of_birth' => $row['date_of_birth'],
                    'cabin_class' => $type === PassengerType::Infant
                        ? null
                        : ($row['cabin_class'] ?? null),
                    'extra_bags' => $type === PassengerType::Infant
                        ? 0
                        : (int) ($row['extra_bags'] ?? 0),
                    'extra_kg' => $type === PassengerType::Infant
                        ? 0
                        : (int) ($row['extra_kg'] ?? 0),
                ]);
            }

            foreach ($passengers as $index => $row) {
                if (PassengerType::from($row['type']) !== PassengerType::Infant) {
                    continue;
                }

                $adult = $created[(int) $row['accompanying_adult']];
                $created[$index]->update([
                    'accompanying_adult_id' => $adult->id,
                    'cabin_class' => $adult->cabin_class,
                ]);
            }

            $this->assignSeats($flight, $passengers, $created);

            foreach ($passengers as $index => $row) {
                $this->corrections->addDocument($created[$index], [
                    'type' => DocumentType::Passport,
                    'number' => $row['passport']['number'],
                    'issuing_country' => $row['passport']['issuing_country'],
                    'expiry_date' => $row['passport']['expiry_date'],
                ], $agent);
            }

            if ($card !== null) {
                $this->chargeFare($reservation, $passengers, $created, $card, $agent);
            }

            return $reservation->load(['passengers', 'payments']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $passengers
     * @param  array<int, Passenger>  $created
     */
    private function chargeFare(
        Reservation $reservation,
        array $passengers,
        array $created,
        CardDetails $card,
        User $agent,
    ): Payment {
        $quotePassengers = [];

        foreach ($passengers as $index => $row) {
            $type = $row['type'] instanceof PassengerType
                ? $row['type']
                : PassengerType::from($row['type']);
            $cabin = $type === PassengerType::Infant
                ? $created[(int) $row['accompanying_adult']]->cabin_class
                : ($row['cabin_class'] instanceof CabinClass
                    ? $row['cabin_class']
                    : CabinClass::from($row['cabin_class']));

            $quotePassengers[] = [
                'cabin_class' => $cabin,
                'type' => $type,
            ];
        }

        $extraBags = 0;
        $extraKg = 0;

        foreach ($created as $passenger) {
            $extraBags += $passenger->extra_bags;
            $extraKg += $passenger->extra_kg;
        }

        $quote = $this->fares->quote($quotePassengers, [
            'extra_bags' => $extraBags,
            'extra_kg' => $extraKg,
        ]);

        $payment = $this->payments->charge(
            $card,
            $quote->totalCents,
            PaymentKind::Fare,
            $agent,
            reservation: $reservation,
        );

        if ($payment->status !== PaymentStatus::Succeeded) {
            throw new PaymentDeclined($payment->failureMessage ?? 'Card declined.');
        }

        return $payment;
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

        $seats = Seat::query()
            ->where('aircraft_id', $flight->aircraft_id)
            ->whereIn('id', array_keys($wanted))
            ->get()
            ->keyBy(fn (Seat $seat): int => (int) $seat->id);

        if ($seats->count() !== count($wanted)) {
            throw new SeatTaken;
        }

        foreach ($wanted as $seatId => $index) {
            $cabin = CabinClass::from($passengers[$index]['cabin_class']);

            if ($seats->get($seatId)->cabin_class !== $cabin) {
                throw new SeatNotAllowed('Choose a seat in that cabin class.');
            }
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
