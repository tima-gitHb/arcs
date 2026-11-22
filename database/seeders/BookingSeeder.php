<?php

namespace Database\Seeders;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\DocumentType;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
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
use App\Support\Journey;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;

/**
 * Extra demo bookings: trip-fare round trips, multi-city, families, SSRs, excess baggage and filler PNRs.
 */
class BookingSeeder extends Seeder
{
    private string $airlineCode;

    private User $reservationAgent;

    private User $checkinAgent;

    private PnrGenerator $pnr;

    private BoardingPassService $passes;

    private int $nameIndex = 50;

    private int $documentNo = 2000000;

    private int $tagNo = 200000;

    public function run(PnrGenerator $pnr, BoardingPassService $passes): void
    {
        $this->pnr = $pnr;
        $this->passes = $passes;
        $this->airlineCode = (string) config('arcs.airline.code');
        $this->reservationAgent = User::where('role', Role::Reservation)->firstOrFail();
        $this->checkinAgent = User::where('role', Role::Checkin)->firstOrFail();

        $this->seedRoundTripTripFare();
        $this->seedMultiCity();
        $this->seedFamily();
        $this->seedConnectionCheckInFirstLegOnly();
        $this->seedExcessBags();
        $this->seedSpecialServices();
        $this->seedFillerBookings();
    }

    private function flightNo(int $number): string
    {
        return $this->airlineCode.$number;
    }

    private function tagPrefix(): string
    {
        return $this->airlineCode;
    }

    private function seedRoundTripTripFare(): void
    {
        $out = Flight::where('flight_no', $this->flightNo(203))->firstOrFail();
        $back = Flight::where('flight_no', $this->flightNo(204))->firstOrFail();

        $reservation = Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $out->id,
            'contact_email' => 'roundtrip@arcs.test',
            'contact_phone' => '+96170000001',
            'created_by' => $this->reservationAgent->id,
        ]);

        app(JourneyService::class)->addSegments($reservation, new Journey([$out], [$back]));

        $passenger = $this->passenger($reservation, $out, visa: 'FR');
        $this->seat($out, $passenger);
        $this->seat($back, $passenger);
    }

    private function seedMultiCity(): void
    {
        $beyIst = Flight::where('flight_no', $this->flightNo(264))->firstOrFail();
        $istCdg = Flight::where('flight_no', $this->flightNo(216))->firstOrFail();
        $cdgBey = Flight::where('flight_no', $this->flightNo(204))->firstOrFail();

        $reservation = Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $beyIst->id,
            'created_by' => $this->reservationAgent->id,
        ]);

        app(JourneyService::class)->addSegments($reservation, new Journey([$beyIst, $istCdg], [$cdgBey]));

        $passenger = $this->passenger($reservation, $beyIst, visa: 'FR');
        foreach ([$beyIst, $istCdg, $cdgBey] as $flight) {
            $this->seat($flight, $passenger);
        }
    }

    private function seedFamily(): void
    {
        $flight = Flight::where('flight_no', $this->flightNo(422))->firstOrFail();

        $reservation = Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $flight->id,
            'created_by' => $this->reservationAgent->id,
        ]);

        $adultOne = $this->passenger($reservation, $flight);
        $adultTwo = $this->passenger($reservation, $flight);
        $child = $this->passenger($reservation, $flight, type: PassengerType::Child);
        $infant = $this->passenger($reservation, $flight, type: PassengerType::Infant, adult: $adultOne);

        foreach ([$adultOne, $adultTwo, $child] as $member) {
            $this->seat($flight, $member);
        }
    }

    /**
     * Connection journey checked in at BEY for the outbound only (return still Booked).
     */
    private function seedConnectionCheckInFirstLegOnly(): void
    {
        $beyIst = Flight::where('flight_no', $this->flightNo(264))->firstOrFail();
        $istCdg = Flight::where('flight_no', $this->flightNo(216))->firstOrFail();

        $reservation = Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $beyIst->id,
            'created_by' => $this->reservationAgent->id,
        ]);

        app(JourneyService::class)->addSegments($reservation, new Journey([$beyIst, $istCdg]));

        $passenger = $this->passenger($reservation, $beyIst, visa: 'FR');
        $this->seat($beyIst, $passenger);
        $this->seat($istCdg, $passenger);
        $this->passes->completeCheckIn($passenger, $this->checkinAgent);
    }

    private function seedExcessBags(): void
    {
        $flight = Flight::where('flight_no', $this->flightNo(201))->firstOrFail();

        $reservation = Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $flight->id,
            'created_by' => $this->reservationAgent->id,
        ]);

        $passenger = $this->passenger($reservation, $flight, visa: 'FR');
        $this->seat($flight, $passenger);

        Bag::create([
            'passenger_id' => $passenger->id,
            'tag_no' => $this->tagPrefix().$this->tagNo++,
            'type' => BagType::Standard,
            'handling' => BagHandling::Hold,
            'weight_kg' => 18,
        ]);
        Bag::create([
            'passenger_id' => $passenger->id,
            'tag_no' => $this->tagPrefix().$this->tagNo++,
            'type' => BagType::Standard,
            'handling' => BagHandling::Hold,
            'weight_kg' => 31,
        ]);
    }

    private function seedSpecialServices(): void
    {
        $flight = Flight::where('flight_no', $this->flightNo(422))->firstOrFail();

        $reservation = Reservation::create([
            'pnr' => $this->pnr->generate(),
            'flight_id' => $flight->id,
            'created_by' => $this->reservationAgent->id,
        ]);

        $wchr = $this->passenger($reservation, $flight);
        $this->seat($flight, $wchr);
        SpecialService::create(['passenger_id' => $wchr->id, 'type' => SpecialServiceCode::WCHR]);

        $child = $this->passenger($reservation, $flight, type: PassengerType::Child);
        $this->seat($flight, $child);
        SpecialService::create([
            'passenger_id' => $child->id,
            'type' => SpecialServiceCode::UMNR,
            'details' => 'Guardian Maria Haddad at CDG',
        ]);
        SpecialService::create([
            'passenger_id' => $child->id,
            'type' => SpecialServiceCode::SPML,
            'details' => 'No nuts; soft food only',
        ]);
    }

    private function seedFillerBookings(): void
    {
        $target = 40;
        $current = Reservation::count();

        if ($current >= $target) {
            return;
        }

        $faker = FakerFactory::create();
        $faker->seed(20261122);

        $flights = Flight::query()
            ->where('status', 'Scheduled')
            ->where('origin', 'BEY')
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->limit(80)
            ->get();

        foreach ($flights as $flight) {
            if ($current >= $target) {
                break;
            }

            $reservation = Reservation::create([
                'pnr' => $this->pnr->generate(),
                'flight_id' => $flight->id,
                'created_by' => $this->reservationAgent->id,
            ]);

            $passenger = $this->passenger($reservation, $flight);
            $this->seat($flight, $passenger);
            $current++;
        }
    }

    private function passenger(
        Reservation $reservation,
        Flight $flight,
        PassengerType $type = PassengerType::Adult,
        ?Passenger $adult = null,
        ?string $visa = null,
    ): Passenger {
        $passenger = Passenger::create([
            'reservation_id' => $reservation->id,
            'first_name' => 'Demo',
            'last_name' => 'Passenger '.$this->nameIndex,
            'type' => $type,
            'cabin_class' => CabinClass::Economy,
            'date_of_birth' => match ($type) {
                PassengerType::Infant => now()->subMonths(8),
                PassengerType::Child => now()->subYears(9),
                default => now()->subYears(30),
            },
            'accompanying_adult_id' => $adult?->id,
        ]);
        $this->nameIndex++;

        TravelDocument::create([
            'passenger_id' => $passenger->id,
            'type' => DocumentType::Passport,
            'number' => 'LR'.$this->documentNo++,
            'issuing_country' => 'LB',
            'expiry_date' => now()->addYears(4)->toDateString(),
        ]);

        if ($visa !== null) {
            TravelDocument::create([
                'passenger_id' => $passenger->id,
                'type' => DocumentType::Visa,
                'number' => 'V'.$this->documentNo++,
                'issuing_country' => $visa,
                'expiry_date' => now()->addYear()->toDateString(),
            ]);
        }

        return $passenger;
    }

    private function seat(Flight $flight, Passenger $passenger): void
    {
        $seat = Seat::where('aircraft_id', $flight->aircraft_id)
            ->where('cabin_class', CabinClass::Economy)
            ->whereDoesntHave('seatAssignments', fn ($q) => $q->where('flight_id', $flight->id))
            ->orderBy('id')
            ->firstOrFail();

        SeatAssignment::create([
            'flight_id' => $flight->id,
            'seat_id' => $seat->id,
            'passenger_id' => $passenger->id,
        ]);
    }
}
