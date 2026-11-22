<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\DocumentType;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\SeatNotAllowed;
use App\Exceptions\SeatTaken;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\Payments\CardDetails;
use App\Support\Journey;
use App\Support\Limits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a reservation and its passengers together (SR-1, SR-4, SR-24).
 * Accepts one flight or a Journey (one-way / connection / round trip) — R11, SR-65.
 * Passports via CorrectionService (R6). Card charge before keep (R9); fare via journeyQuote (SR-69).
 */
class ReservationService
{
    public function __construct(
        private PnrGenerator $pnrs,
        private CorrectionService $corrections,
        private FareService $fares,
        private PaymentService $payments,
        private JourneyService $journeys,
    ) {}

    /**
     * @param  array<int, array{first_name: string, last_name: string, type: string, date_of_birth: string, cabin_class?: string|null, accompanying_adult?: int|string|null, passport: array{number: string, issuing_country: string, expiry_date: string}, seat_id?: int|string|null, seats?: array<int|string, int|string|null>, extra_bags?: int|string|null, extra_kg?: int|string|null}>  $passengers
     * @param  array{contact_email?: string|null, contact_phone?: string|null}  $contact
     */
    public function create(
        Flight|Journey $journeyOrFlight,
        User $agent,
        array $passengers,
        array $contact = [],
        ?CardDetails $card = null,
    ): Reservation {
        $journey = $journeyOrFlight instanceof Journey
            ? $journeyOrFlight
            : Journey::oneWay($journeyOrFlight);

        $this->journeys->validate($journey);
        $this->guardInfants($passengers);

        return DB::transaction(function () use ($journey, $agent, $passengers, $contact, $card) {
            $first = $journey->outbound[0];

            $reservation = Reservation::create([
                'pnr' => $this->pnrs->generate(),
                'flight_id' => $first->id,
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

            $this->journeys->addSegments($reservation, $journey);

            $flights = array_map(fn (array $leg) => $leg[0], $journey->legs());
            $this->assignSeats($flights, $passengers, $created);

            foreach ($passengers as $index => $row) {
                $this->corrections->addDocument($created[$index], [
                    'type' => DocumentType::Passport,
                    'number' => $row['passport']['number'],
                    'issuing_country' => $row['passport']['issuing_country'],
                    'expiry_date' => $row['passport']['expiry_date'],
                ], $agent);
            }

            if ($card !== null) {
                $this->chargeFare($reservation, $created, $card, $agent);
            }

            return $reservation->load(['passengers', 'payments', 'segments.flight']);
        });
    }

    /**
     * @param  array<int, Passenger>  $created
     */
    private function chargeFare(
        Reservation $reservation,
        array $created,
        CardDetails $card,
        User $agent,
    ): Payment {
        $quote = $this->fares->journeyQuote($reservation);

        $extraBags = 0;
        $extraKg = 0;
        foreach ($created as $passenger) {
            $extraBags += $passenger->extra_bags;
            $extraKg += $passenger->extra_kg;
        }

        $amount = $quote->totalCents
            + $extraBags * (int) (Fee::query()->where('code', FeeCode::ExtraBag)->value('amount_cents') ?? 0)
            + $extraKg * (int) (Fee::query()->where('code', FeeCode::ExcessKg)->value('amount_cents') ?? 0);

        $payment = $this->payments->charge(
            $card,
            $amount,
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
     * @param  list<Flight>  $flights
     * @param  array<int, array<string, mixed>>  $passengers
     * @param  array<int, Passenger>  $created
     */
    private function assignSeats(array $flights, array $passengers, array $created): void
    {
        foreach ($flights as $legIndex => $flight) {
            $this->assignSeatsOnFlight($flight, $passengers, $created, $legIndex === 0);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $passengers
     * @param  array<int, Passenger>  $created
     */
    private function assignSeatsOnFlight(Flight $flight, array $passengers, array $created, bool $isFirst): void
    {
        $wanted = [];

        foreach ($passengers as $index => $row) {
            if (PassengerType::from($row['type']) === PassengerType::Infant) {
                continue;
            }

            $seatId = $this->seatIdFor($row, $flight, $isFirst);

            if ($seatId === null) {
                continue;
            }

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

        if (SeatAssignment::query()
            ->where('flight_id', $flight->id)
            ->whereIn('seat_id', array_keys($wanted))
            ->lockForUpdate()
            ->exists()) {
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
     * @param  array<string, mixed>  $row
     */
    private function seatIdFor(array $row, Flight $flight, bool $isFirst): ?int
    {
        $seats = $row['seats'] ?? null;

        if (is_array($seats)) {
            $value = $seats[$flight->id] ?? $seats[(string) $flight->id] ?? null;
            if ($value !== null && $value !== '') {
                return (int) $value;
            }
        }

        if ($isFirst) {
            $legacy = $row['seat_id'] ?? null;
            if ($legacy !== null && $legacy !== '') {
                return (int) $legacy;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $passengers
     */
    private function guardInfants(array $passengers): void
    {
        $perAdult = [];

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

            $perAdult[(int) $adultIndex] = ($perAdult[(int) $adultIndex] ?? 0) + 1;
        }

        $max = Limits::maxInfantsPerAdult();
        foreach ($perAdult as $count) {
            if ($count > $max) {
                throw new InvalidArgumentException("An adult may accompany at most {$max} infant".($max === 1 ? '' : 's').'.');
            }
        }
    }
}
