<?php

namespace Database\Seeders;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\DocumentType;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Models\Aircraft;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\SpecialService;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use App\Services\PnrGenerator;
use App\Services\StatusService;
use App\Support\Journey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Nine sample flights on OD-MRA, with times relative to now(): six from Beirut, two from Paris
 * and one from Istanbul to Paris (B13a), so each station has its own demo. Every flight has an
 * arrival time. B13b adds a BEY→IST→CDG connection (ME264 + ME216) and a BEY→CDG→BEY round trip
 * (ME203 + ME204), all passengers Booked.
 * Times are stored in UTC; pages show them in the origin airport's local time.
 * Every status is reached through StatusService, so audit_log fills naturally.
 * Check-ins go through BoardingPassService::completeCheckIn(), so passengers get their pass.
 * Everyone who is checked in holds a visa for the destination (SR-49); some Booked ME 201
 * passengers do not, to show the entry check asking for a reason.
 *
 * StatusService only allows check-in inside the check-in window. For flights whose
 * window is already over (ME 305, ME 110), we briefly move the clock back with at().
 */
class FlightSeeder extends Seeder
{
    /**
     * Flight time per route in minutes (real block times, rounded): arrives_at = departure + this (B13b).
     */
    private const BLOCK_MINUTES = [
        'BEY-CDG' => 285,
        'CDG-BEY' => 255,
        'BEY-LHR' => 305,
        'BEY-DXB' => 180,
        'BEY-FCO' => 200,
        'BEY-IST' => 110,
        'IST-CDG' => 225,
    ];

    private const FIRST_NAMES = [
        'Karim', 'Lara', 'Nadim', 'Rita', 'Fadi', 'Nour', 'Elie', 'Yara', 'Marc', 'Dana',
        'Ziad', 'Christelle', 'Hadi', 'Maria', 'Tarek', 'Joelle', 'Samir', 'Rania', 'Georges', 'Lina',
        'Walid', 'Carla', 'Bassam', 'Sara', 'Antoine', 'Mira', 'Omar', 'Tania', 'Charbel', 'Reem',
    ];

    private const LAST_NAMES = [
        'Haddad', 'Khalil', 'Aoun', 'Frem', 'Mansour', 'Sleiman', 'Hajj', 'Karam', 'Rizk', 'Chami',
        'Najjar', 'Tannous', 'Saad', 'Gemayel', 'Azar', 'Bou Khalil', 'Hamdan', 'Sfeir', 'Kassab', 'Yammine',
    ];

    private StatusService $status;

    private BoardingPassService $passes;

    private PnrGenerator $pnr;

    private Carbon $base;

    private User $reservationAgent;

    private User $checkinAgent;

    private User $gateAgent;

    private User $manager;

    private User $parisCheckinAgent;

    private Aircraft $aircraft;

    private int $nameIndex = 0;

    private int $documentNo = 1000000;

    private int $tagNo = 100000;

    /** @var array<int, int> next free seat index per flight id */
    private array $nextSeat = [];

    public function run(StatusService $status, BoardingPassService $passes, PnrGenerator $pnr): void
    {
        $this->status = $status;
        $this->passes = $passes;
        $this->pnr = $pnr;
        $this->base = now()->startOfMinute();

        $this->reservationAgent = User::where('role', Role::Reservation)->firstOrFail();
        $this->checkinAgent = User::where('role', Role::Checkin)->firstOrFail();
        $this->gateAgent = User::where('role', Role::Gate)->firstOrFail();
        $this->manager = User::where('role', Role::Manager)->firstOrFail();
        $this->parisCheckinAgent = User::where('role', Role::Checkin)->where('airport_code', 'CDG')->firstOrFail();
        $this->aircraft = Aircraft::where('registration', 'OD-MRA')->firstOrFail();

        $this->seedParis();
        $this->seedDubai();
        $this->seedLondon();
        $this->seedRome();
        $this->seedParisToBeirut();
        $this->seedIstanbulToParis();
        $this->seedConnectionViaIstanbul();
        $this->seedRoundTripToParis();
    }

    /**
     * B13b connection BEY→IST→CDG: ME264 BEY→IST (check-in open now, lands at IST 130 min before
     * ME216 leaves; IST needs 60) then ME216 IST→CDG. 3 passengers Booked on both flights, with a
     * seat on each and a French visa: checking in at BEY checks in both flights, bags go to CDG.
     */
    private function seedConnectionViaIstanbul(): void
    {
        $toIstanbul = $this->flight('ME264', 'BEY', 'IST', gate: 'A5', departs: 120, opens: -60, closes: 75);
        $this->status->setFlightStatus($toIstanbul, FlightStatus::CheckinOpen, $this->manager);
        $toParis = Flight::where('flight_no', 'ME216')->firstOrFail();

        $reservation = $this->reservation($toIstanbul);
        app(JourneyService::class)->addSegments($reservation, new Journey([$toIstanbul, $toParis]));

        for ($i = 0; $i < 3; $i++) {
            $passenger = $this->passenger($toIstanbul, visaCountry: 'FR', reservation: $reservation);
            $this->seat($toIstanbul, $passenger);
            $this->seat($toParis, $passenger);
        }
    }

    /**
     * B13b round trip BEY→CDG→BEY: out on ME203 tomorrow, back on ME204 CDG→BEY in 3 days.
     * 2 passengers Booked both ways; the return is checked in at CDG by the Paris staff.
     */
    private function seedRoundTripToParis(): void
    {
        $out = $this->flight('ME203', 'BEY', 'CDG', gate: null, departs: 24 * 60 + 120, opens: 24 * 60 - 60, closes: 24 * 60 + 75);
        $back = $this->flight('ME204', 'CDG', 'BEY', gate: null, departs: 3 * 24 * 60, opens: 3 * 24 * 60 - 180, closes: 3 * 24 * 60 - 45);

        $reservation = $this->reservation($out);
        app(JourneyService::class)->addSegments($reservation, new Journey([$out], [$back]));

        for ($i = 0; $i < 2; $i++) {
            $passenger = $this->passenger($out, visaCountry: 'FR', reservation: $reservation);
            $this->seat($out, $passenger);
        }
    }

    private function reservation(Flight $flight): Reservation
    {
        return Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $flight->id,
            'created_by' => $this->reservationAgent->id,
        ]);
    }

    /**
     * ME 201 Beirut → Paris: check-in open now.
     * 8 Booked (incl. one expired passport, a BLND and a PETC passenger),
     * 4 CheckedIn (an adult with an infant and a stroller; a WCHR passenger with a wheelchair;
     * one with sports equipment), 1 Cancelled.
     */
    private function seedParis(): void
    {
        $flight = $this->flight('ME201', 'BEY', 'CDG', gate: 'A3', departs: 180, opens: -60, closes: 135);
        $this->status->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->manager);

        // 7 ordinary Booked passengers, some with a visa for France.
        $booked = [];
        for ($i = 0; $i < 7; $i++) {
            $booked[] = $this->passenger($flight, visaCountry: $i % 3 === 0 ? 'FR' : null);
        }
        $this->specialService($booked[0], SpecialServiceCode::BLND);
        $this->specialService($booked[1], SpecialServiceCode::PETC, 'Cat, 6 kg with carrier');

        // An adult travelling with an infant on the same booking (infant: no seat, SR-24).
        // Both checked in; the stroller is delivered at the aircraft door and is free (SR-43).
        $adult = $this->passenger($flight, visaCountry: 'FR');
        $infant = $this->passenger($flight, type: PassengerType::Infant, adult: $adult, visaCountry: 'FR');
        $this->seat($flight, $adult);
        $this->passes->completeCheckIn($adult, $this->checkinAgent);
        $this->passes->completeCheckIn($infant, $this->checkinAgent);
        $this->bag($adult);
        $this->bag($adult, BagType::Stroller, BagHandling::GateDelivery, 9.0);

        // Passport already expired: check-in will be refused with "Document expired".
        $this->passenger($flight, passportExpiry: $this->base->copy()->subMonths(2));

        // 2 more checked in, each with a seat and a bag.
        $checkedIn = [];
        for ($i = 0; $i < 2; $i++) {
            $passenger = $this->passenger($flight, visaCountry: 'FR');
            $this->seat($flight, $passenger);
            $this->passes->completeCheckIn($passenger, $this->checkinAgent);
            $this->bag($passenger);
            $checkedIn[] = $passenger;
        }

        // The first needs a wheelchair to the aircraft door (SR-44); it is free (SR-43).
        $this->specialService($checkedIn[0], SpecialServiceCode::WCHR);
        $this->bag($checkedIn[0], BagType::Wheelchair, BagHandling::GateDelivery, 14.0);

        // The second also checks in a bike.
        $this->bag($checkedIn[1], BagType::SportsEquipment, BagHandling::Hold, 21.5);

        // Booked with a seat, then cancelled: the seat is released (SR-5).
        $cancelled = $this->passenger($flight);
        $this->seat($flight, $cancelled);
        $this->status->cancel($cancelled, $this->reservationAgent);
    }

    /**
     * ME 305 Beirut → Dubai: boarding now, departs in 40 minutes.
     * 8 CheckedIn with seats and bags, of which 5 already Boarded, and 1 late passenger still Booked.
     */
    private function seedDubai(): void
    {
        $flight = $this->flight('ME305', 'BEY', 'DXB', gate: 'B7', departs: 40, opens: -140, closes: -5);

        $passengers = $this->at(-120, function () use ($flight) {
            $this->status->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->manager);

            $passengers = [];
            for ($i = 0; $i < 8; $i++) {
                $passenger = $this->passenger($flight, visaCountry: 'AE');
                $this->seat($flight, $passenger);
                $this->passes->completeCheckIn($passenger, $this->checkinAgent);
                $this->bag($passenger);
                $passengers[] = $passenger;
            }

            return $passengers;
        });

        $this->at(-10, fn () => $this->status->setFlightStatus($flight, FlightStatus::Boarding, $this->gateAgent));

        foreach (array_slice($passengers, 0, 5) as $passenger) {
            $this->status->board($passenger, $this->gateAgent);
        }

        // Still Booked, with no seat: the demo passenger for a late check-in at the gate (SR-29).
        $this->passenger($flight);
    }

    /**
     * ME 110 Beirut → London: boarding closed earlier today through closeBoarding().
     * 6 Boarded, 2 NoShow whose bags are returned for offloading (SR-12).
     */
    private function seedLondon(): void
    {
        $flight = $this->flight('ME110', 'BEY', 'LHR', gate: 'A1', departs: -60, opens: -240, closes: -105);

        $passengers = $this->at(-180, function () use ($flight) {
            $this->status->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->manager);

            $passengers = [];
            for ($i = 0; $i < 8; $i++) {
                $passenger = $this->passenger($flight, visaCountry: 'GB');
                $this->seat($flight, $passenger);
                $this->passes->completeCheckIn($passenger, $this->checkinAgent);
                $this->bag($passenger);
                $passengers[] = $passenger;
            }

            return $passengers;
        });

        $this->at(-100, function () use ($flight, $passengers) {
            $this->status->setFlightStatus($flight, FlightStatus::Boarding, $this->gateAgent);

            foreach (array_slice($passengers, 0, 6) as $passenger) {
                $this->status->board($passenger, $this->gateAgent);
            }
        });

        // The last 2 never came to the gate: they become NoShow.
        $this->at(-75, fn () => $this->status->closeBoarding($flight, $this->gateAgent));
    }

    /**
     * ME 422 Beirut → Rome: tomorrow, still Scheduled, no passengers yet.
     */
    private function seedRome(): void
    {
        $this->flight('ME422', 'BEY', 'FCO', gate: null, departs: 24 * 60, opens: 24 * 60 - 180, closes: 24 * 60 - 45);
    }

    /**
     * ME 202 Paris → Beirut, the return flight: check-in open now at CDG, for the Paris staff (B13a).
     * 5 Booked and 2 CheckedIn by the Paris check-in agent. Lebanese passports: no visa needed.
     */
    private function seedParisToBeirut(): void
    {
        $flight = $this->flight('ME202', 'CDG', 'BEY', gate: 'K32', departs: 240, opens: -30, closes: 195);
        $this->status->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->manager);

        for ($i = 0; $i < 5; $i++) {
            $this->passenger($flight);
        }

        for ($i = 0; $i < 2; $i++) {
            $passenger = $this->passenger($flight);
            $this->seat($flight, $passenger);
            $this->passes->completeCheckIn($passenger, $this->parisCheckinAgent);
            $this->bag($passenger);
        }
    }

    /**
     * ME 216 Istanbul → Paris: departs in 6 hours, still Scheduled, 4 Booked with a French visa.
     * Not a Paris flight for check-in (it departs from IST); it shows in Paris as an arrival later (B13b).
     */
    private function seedIstanbulToParis(): void
    {
        $flight = $this->flight('ME216', 'IST', 'CDG', gate: null, departs: 360, opens: 180, closes: 315);

        for ($i = 0; $i < 4; $i++) {
            $this->passenger($flight, visaCountry: 'FR');
        }
    }

    /**
     * Create a flight on OD-MRA. Times are minutes from now.
     */
    private function flight(string $number, string $origin, string $destination, ?string $gate, int $departs, int $opens, int $closes): Flight
    {
        return Flight::create([
            'flight_no' => $number,
            'origin' => $origin,
            'destination' => $destination,
            'aircraft_id' => $this->aircraft->id,
            'departure_at' => $this->minutes($departs),
            'arrives_at' => $this->minutes($departs + self::BLOCK_MINUTES[$origin.'-'.$destination]),
            'gate' => $gate,
            'checkin_opens_at' => $this->minutes($opens),
            'checkin_closes_at' => $this->minutes($closes),
        ]);
    }

    /**
     * Create a Booked passenger with a valid passport (unless an expiry is given).
     * An infant joins the adult's reservation; everyone else gets their own.
     */
    private function passenger(
        Flight $flight,
        PassengerType $type = PassengerType::Adult,
        ?Passenger $adult = null,
        ?Carbon $passportExpiry = null,
        ?string $visaCountry = null,
        ?Reservation $reservation = null,
    ): Passenger {
        $reservationId = $adult?->reservation_id ?? $reservation?->id ?? $this->reservation($flight)->id;

        $passenger = Passenger::create([
            'reservation_id' => $reservationId,
            'first_name' => self::FIRST_NAMES[$this->nameIndex % count(self::FIRST_NAMES)],
            'last_name' => $adult?->last_name ?? self::LAST_NAMES[$this->nameIndex % count(self::LAST_NAMES)],
            'type' => $type,
            'cabin_class' => CabinClass::Economy,
            'date_of_birth' => $type === PassengerType::Infant
                ? $this->base->copy()->subMonths(8)
                : $this->base->copy()->subYears(25 + $this->nameIndex % 40),
            'accompanying_adult_id' => $adult?->id,
        ]);
        $this->nameIndex++;

        $this->document($passenger, DocumentType::Passport, 'LB', $passportExpiry ?? $this->base->copy()->addYears(4));

        if ($visaCountry) {
            $this->document($passenger, DocumentType::Visa, $visaCountry, $this->base->copy()->addYear());
        }

        return $passenger;
    }

    private function document(Passenger $passenger, DocumentType $type, string $country, Carbon $expiry): void
    {
        TravelDocument::create([
            'passenger_id' => $passenger->id,
            'type' => $type,
            'number' => ($type === DocumentType::Passport ? 'LR' : 'V').$this->documentNo++,
            'issuing_country' => $country,
            'expiry_date' => $expiry->toDateString(),
        ]);
    }

    /**
     * Give the passenger the next free Economy seat (rows 6–30) on this flight.
     */
    private function seat(Flight $flight, Passenger $passenger): void
    {
        $index = $this->nextSeat[$flight->id] ?? 0;
        $this->nextSeat[$flight->id] = $index + 1;

        $seat = Seat::where('aircraft_id', $this->aircraft->id)
            ->where('cabin_class', CabinClass::Economy)
            ->orderBy('id')
            ->skip($index)
            ->firstOrFail();

        SeatAssignment::create([
            'flight_id' => $flight->id,
            'seat_id' => $seat->id,
            'passenger_id' => $passenger->id,
        ]);
    }

    private function bag(
        Passenger $passenger,
        BagType $type = BagType::Standard,
        BagHandling $handling = BagHandling::Hold,
        ?float $weight = null,
    ): void {
        Bag::create([
            'passenger_id' => $passenger->id,
            'tag_no' => 'ME'.$this->tagNo++,
            'type' => $type,
            'handling' => $handling,
            'weight_kg' => $weight ?? 15 + ($this->tagNo % 8),
        ]);
    }

    private function specialService(Passenger $passenger, SpecialServiceCode $code, ?string $details = null): void
    {
        SpecialService::create([
            'passenger_id' => $passenger->id,
            'type' => $code,
            'details' => $details,
        ]);
    }

    private function minutes(int $minutes): Carbon
    {
        return $this->base->copy()->addMinutes($minutes);
    }

    /**
     * Run $action as if the clock showed now + $minutes, then put the clock back.
     */
    private function at(int $minutes, callable $action): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($this->minutes($minutes));

        try {
            return $action();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
