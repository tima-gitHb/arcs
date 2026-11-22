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
use App\Models\Airport;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\SpecialService;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use App\Services\PnrGenerator;
use App\Services\StatusService;
use App\Support\Journey;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Classic demo flights (tests depend on their numbers and mixes) plus a 14-day BEY schedule.
 * Times are stored in UTC; every status change goes through StatusService.
 */
class FlightSeeder extends Seeder
{
    private const BLOCK_MINUTES = [
        'BEY-CDG' => 275,
        'CDG-BEY' => 275,
        'BEY-LHR' => 305,
        'LHR-BEY' => 305,
        'BEY-DXB' => 195,
        'DXB-BEY' => 195,
        'BEY-FCO' => 205,
        'FCO-BEY' => 205,
        'BEY-IST' => 115,
        'IST-BEY' => 115,
        'IST-CDG' => 225,
        'CDG-IST' => 225,
        'BEY-FRA' => 250,
        'FRA-BEY' => 250,
        'BEY-ATH' => 125,
        'ATH-BEY' => 125,
        'BEY-DOH' => 180,
        'DOH-BEY' => 180,
        'BEY-CAI' => 80,
        'CAI-BEY' => 80,
        'BEY-AMM' => 60,
        'AMM-BEY' => 60,
        'BEY-JED' => 165,
        'JED-BEY' => 165,
    ];

    private const GATES_A = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8'];

    private const GATES_B = ['B1', 'B2', 'B3', 'B4', 'B5', 'B6'];

    /** @var array<int, int> classic flight numbers reserved for demos */
    private const RESERVED_NUMBERS = [110, 201, 202, 203, 204, 216, 264, 305, 422];

    private const FIRST_NAMES = [
        'Karim', 'Lara', 'Nadim', 'Rita', 'Fadi', 'Nour', 'Elie', 'Yara', 'Marc', 'Dana',
        'Ziad', 'Christelle', 'Hadi', 'Maria', 'Tarek', 'Joelle', 'Samir', 'Rania', 'Georges', 'Lina',
        'Walid', 'Carla', 'Bassam', 'Sara', 'Antoine', 'Mira', 'Omar', 'Tania', 'Charbel', 'Reem',
    ];

    private const LAST_NAMES = [
        'Haddad', 'Khalil', 'Aoun', 'Frem', 'Mansour', 'Sleiman', 'Hajj', 'Karam', 'Rizk', 'Chami',
        'Najjar', 'Tannous', 'Saad', 'Gemayel', 'Azar', 'Bou Khalil', 'Hamdan', 'Sfeir', 'Kassab', 'Yammine',
    ];

    private string $airlineCode;

    private StatusService $status;

    private BoardingPassService $passes;

    private PnrGenerator $pnr;

    private Carbon $base;

    private User $reservationAgent;

    private User $checkinAgent;

    private User $gateAgent;

    private User $manager;

    private User $parisCheckinAgent;

    private Aircraft $demoAircraft;

    /** @var list<Aircraft> */
    private array $fleet = [];

    private int $nameIndex = 0;

    private int $documentNo = 1000000;

    private int $tagNo = 100000;

    /** @var array<int, int> next free Economy seat index per flight id */
    private array $nextSeat = [];

    /** @var array<int, Carbon> when each aircraft is free again */
    private array $aircraftFreeAt = [];

    private int $europeNumber = 210;

    private int $gulfNumber = 430;

    private int $regionalNumber = 600;

    public function run(StatusService $status, BoardingPassService $passes, PnrGenerator $pnr): void
    {
        $this->status = $status;
        $this->passes = $passes;
        $this->pnr = $pnr;
        $this->base = now()->startOfMinute();
        $this->airlineCode = (string) config('arcs.airline.code');

        $this->reservationAgent = User::where('role', Role::Reservation)->firstOrFail();
        $this->checkinAgent = User::where('role', Role::Checkin)->firstOrFail();
        $this->gateAgent = User::where('role', Role::Gate)->firstOrFail();
        $this->manager = User::where('role', Role::Manager)->firstOrFail();
        $this->parisCheckinAgent = User::where('role', Role::Checkin)->where('airport_code', 'CDG')->firstOrFail();

        $this->fleet = Aircraft::query()->orderBy('registration')->get()->all();
        foreach ($this->fleet as $craft) {
            $this->aircraftFreeAt[$craft->id] = $this->base->copy();
        }

        $this->demoAircraft = Aircraft::where('registration', 'OD-ARA')->firstOrFail();

        $this->seedParis();
        $this->seedDubai();
        $this->seedLondon();
        $this->seedRome();
        $this->seedParisToBeirut();
        $this->seedIstanbulToParis();
        $this->seedConnectionViaIstanbul();
        $this->seedRoundTripToParis();
        $this->seedSchedule();
    }

    private function flightNo(int $number): string
    {
        return $this->airlineCode.$number;
    }

    private function tagPrefix(): string
    {
        return $this->airlineCode;
    }

    private function seedConnectionViaIstanbul(): void
    {
        $toIstanbul = $this->flight($this->flightNo(264), 'BEY', 'IST', aircraft: $this->demoAircraft, gate: 'A5', departs: 120, opens: -60, closes: 75);
        $this->status->setFlightStatus($toIstanbul, FlightStatus::CheckinOpen, $this->manager);
        $toParis = Flight::where('flight_no', $this->flightNo(216))->firstOrFail();

        $reservation = $this->reservation($toIstanbul);
        app(JourneyService::class)->addSegments($reservation, new Journey([$toIstanbul, $toParis]));

        for ($i = 0; $i < 3; $i++) {
            $passenger = $this->passenger($toIstanbul, visaCountry: 'FR', reservation: $reservation);
            $this->seat($toIstanbul, $passenger);
            $this->seat($toParis, $passenger);
        }
    }

    private function seedRoundTripToParis(): void
    {
        $out = $this->flight($this->flightNo(203), 'BEY', 'CDG', aircraft: $this->demoAircraft, gate: null, departs: 24 * 60 + 120, opens: 24 * 60 - 60, closes: 24 * 60 + 75);
        $back = $this->flight($this->flightNo(204), 'CDG', 'BEY', aircraft: $this->demoAircraft, gate: null, departs: 3 * 24 * 60, opens: 3 * 24 * 60 - 180, closes: 3 * 24 * 60 - 45);

        $reservation = $this->reservation($out);
        app(JourneyService::class)->addSegments($reservation, new Journey([$out], [$back]));

        for ($i = 0; $i < 2; $i++) {
            $passenger = $this->passenger($out, visaCountry: 'FR', reservation: $reservation);
            $this->seat($out, $passenger);
        }
    }

    private function seedParis(): void
    {
        $flight = $this->flight($this->flightNo(201), 'BEY', 'CDG', aircraft: $this->demoAircraft, gate: 'A3', departs: 180, opens: -60, closes: 135);
        $this->status->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->manager);

        $booked = [];
        for ($i = 0; $i < 7; $i++) {
            $booked[] = $this->passenger($flight, visaCountry: $i % 3 === 0 ? 'FR' : null);
        }
        $this->specialService($booked[0], SpecialServiceCode::BLND);
        $this->specialService($booked[1], SpecialServiceCode::PETC, 'Cat, 6 kg with carrier');

        $adult = $this->passenger($flight, visaCountry: 'FR');
        $infant = $this->passenger($flight, type: PassengerType::Infant, adult: $adult, visaCountry: 'FR');
        $this->seat($flight, $adult);
        $this->passes->completeCheckIn($adult, $this->checkinAgent);
        $this->passes->completeCheckIn($infant, $this->checkinAgent);
        $this->bag($adult);
        $this->bag($adult, BagType::Stroller, BagHandling::GateDelivery, 9.0);

        $this->passenger($flight, passportExpiry: $this->base->copy()->subMonths(2));

        $checkedIn = [];
        for ($i = 0; $i < 2; $i++) {
            $passenger = $this->passenger($flight, visaCountry: 'FR');
            $this->seat($flight, $passenger);
            $this->passes->completeCheckIn($passenger, $this->checkinAgent);
            $this->bag($passenger);
            $checkedIn[] = $passenger;
        }

        $this->specialService($checkedIn[0], SpecialServiceCode::WCHR);
        $this->bag($checkedIn[0], BagType::Wheelchair, BagHandling::GateDelivery, 14.0);
        $this->bag($checkedIn[1], BagType::SportsEquipment, BagHandling::Hold, 21.5);

        $cancelled = $this->passenger($flight);
        $this->seat($flight, $cancelled);
        $this->status->cancel($cancelled, $this->reservationAgent);
    }

    private function seedDubai(): void
    {
        $craft = $this->pickAircraft('OD-ARB') ?? $this->demoAircraft;
        $flight = $this->flight($this->flightNo(305), 'BEY', 'DXB', aircraft: $craft, gate: 'B7', departs: 40, opens: -140, closes: -5);

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

        $this->passenger($flight);
    }

    private function seedLondon(): void
    {
        $craft = $this->pickAircraft('OD-ARC') ?? $this->demoAircraft;
        $flight = $this->flight($this->flightNo(110), 'BEY', 'LHR', aircraft: $craft, gate: 'A1', departs: -60, opens: -240, closes: -105);

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

        $bags = $this->at(-75, fn () => $this->status->closeBoarding($flight, $this->gateAgent));

        $this->at(-70, function () use ($bags) {
            $bagsService = app(BagService::class);
            foreach ($bags as $bag) {
                $bagsService->confirmOffload($bag, $this->gateAgent);
            }
        });

        $this->at(-65, fn () => $this->status->setFlightStatus($flight->fresh(), FlightStatus::Departed, $this->manager));
    }

    private function seedRome(): void
    {
        $craft = $this->pickAircraft('OD-ARD') ?? $this->demoAircraft;
        $this->flight($this->flightNo(422), 'BEY', 'FCO', aircraft: $craft, gate: null, departs: 24 * 60, opens: 24 * 60 - 180, closes: 24 * 60 - 45);
    }

    private function seedParisToBeirut(): void
    {
        $craft = $this->pickAircraft('OD-ARE') ?? $this->demoAircraft;
        $flight = $this->flight($this->flightNo(202), 'CDG', 'BEY', aircraft: $craft, gate: 'K32', departs: 240, opens: -30, closes: 195);
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

    private function seedIstanbulToParis(): void
    {
        $craft = $this->pickAircraft('OD-ARF') ?? $this->demoAircraft;
        $flight = $this->flight($this->flightNo(216), 'IST', 'CDG', aircraft: $craft, gate: null, departs: 360, opens: 180, closes: 315);

        for ($i = 0; $i < 4; $i++) {
            $this->passenger($flight, visaCountry: 'FR');
        }
    }

    private function seedSchedule(): void
    {
        $faker = FakerFactory::create();
        $faker->seed(20261122);

        $routes = [
            'CDG' => 'europe',
            'LHR' => 'europe',
            'FRA' => 'europe',
            'FCO' => 'europe',
            'ATH' => 'europe',
            'DXB' => 'gulf',
            'DOH' => 'gulf',
            'JED' => 'gulf',
            'CAI' => 'regional',
            'AMM' => 'regional',
            'IST' => 'regional',
        ];

        $beirutTz = Airport::timezoneOf('BEY');
        $start = Carbon::now($beirutTz)->startOfDay()->addDay();

        for ($day = 0; $day < 14; $day++) {
            $localDay = $start->copy()->addDays($day);

            foreach ($routes as $destination => $region) {
                $legs = $faker->boolean(35) ? 2 : 1;

                for ($leg = 0; $leg < $legs; $leg++) {
                    $hour = $leg === 0 && $day === 0 && $destination === 'CAI'
                        ? 0
                        : $faker->numberBetween(6, 22);
                    $minute = ($day === 0 && $destination === 'CAI' && $hour === 0) ? 0 : ($faker->boolean(30) ? 30 : 0);

                    $outboundAt = $localDay->copy()->setTime($hour, $minute)->utc();
                    $this->scheduleLeg('BEY', $destination, $region, $outboundAt, $faker);

                    $returnHour = min(23, $hour + $faker->numberBetween(8, 12));
                    $returnAt = $localDay->copy()->setTime($returnHour, $minute)->utc();
                    if ($returnAt->lessThanOrEqualTo($outboundAt)) {
                        $returnAt = $outboundAt->copy()->addHours(10);
                    }
                    $this->scheduleLeg($destination, 'BEY', $region, $returnAt, $faker);
                }
            }
        }
    }

    private function scheduleLeg(string $origin, string $destination, string $region, Carbon $departureUtc, $faker): void
    {
        $number = $this->nextScheduleNumber($region);
        $key = $origin.'-'.$destination;
        $block = self::BLOCK_MINUTES[$key] ?? 180;
        $arrival = $departureUtc->copy()->addMinutes($block);

        $aircraft = $this->assignAircraft($departureUtc, $arrival);
        $gate = $origin === 'BEY'
            ? $faker->randomElement(self::GATES_A)
            : ($destination === 'BEY' ? $faker->randomElement(self::GATES_B) : null);

        Flight::create([
            'flight_no' => $this->flightNo($number),
            'origin' => $origin,
            'destination' => $destination,
            'aircraft_id' => $aircraft->id,
            'departure_at' => $departureUtc,
            'arrives_at' => $arrival,
            'gate' => $gate,
            'checkin_opens_at' => $departureUtc->copy()->subHours(3),
            'checkin_closes_at' => $departureUtc->copy()->subMinutes(45),
        ]);
    }

    private function nextScheduleNumber(string $region): int
    {
        do {
            $number = match ($region) {
                'europe' => $this->europeNumber++,
                'gulf' => $this->gulfNumber++,
                default => $this->regionalNumber++,
            };
        } while (in_array($number, self::RESERVED_NUMBERS, true));

        return $number;
    }

    private function assignAircraft(Carbon $departs, Carbon $arrives): Aircraft
    {
        $turnaroundEnd = $departs->copy()->subMinutes(45);
        $best = null;
        $bestFree = null;

        foreach ($this->fleet as $craft) {
            $freeAt = $this->aircraftFreeAt[$craft->id];
            if ($freeAt->lessThanOrEqualTo($turnaroundEnd)) {
                if ($best === null || $freeAt->lessThan($bestFree)) {
                    $best = $craft;
                    $bestFree = $freeAt;
                }
            }
        }

        if ($best === null) {
            $best = $this->fleet[array_key_first($this->fleet)];
        }

        $this->aircraftFreeAt[$best->id] = $arrives->copy()->addMinutes(45);

        return $best;
    }

    private function pickAircraft(string $registration): ?Aircraft
    {
        return collect($this->fleet)->firstWhere('registration', $registration);
    }

    private function reservation(Flight $flight): Reservation
    {
        return Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $flight->id,
            'created_by' => $this->reservationAgent->id,
        ]);
    }

    private function flight(
        string $number,
        string $origin,
        string $destination,
        Aircraft $aircraft,
        ?string $gate,
        int $departs,
        int $opens,
        int $closes,
    ): Flight {
        $block = self::BLOCK_MINUTES[$origin.'-'.$destination] ?? 180;

        return Flight::create([
            'flight_no' => $number,
            'origin' => $origin,
            'destination' => $destination,
            'aircraft_id' => $aircraft->id,
            'departure_at' => $this->minutes($departs),
            'arrives_at' => $this->minutes($departs + $block),
            'gate' => $gate,
            'checkin_opens_at' => $this->minutes($opens),
            'checkin_closes_at' => $this->minutes($closes),
        ]);
    }

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
                : ($type === PassengerType::Child
                    ? $this->base->copy()->subYears(10)
                    : $this->base->copy()->subYears(25 + $this->nameIndex % 40)),
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

    private function seat(Flight $flight, Passenger $passenger): void
    {
        $index = $this->nextSeat[$flight->id] ?? 0;
        $this->nextSeat[$flight->id] = $index + 1;

        $seat = Seat::where('aircraft_id', $flight->aircraft_id)
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
            'tag_no' => $this->tagPrefix().$this->tagNo++,
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
