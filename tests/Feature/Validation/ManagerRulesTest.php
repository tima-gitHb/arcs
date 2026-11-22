<?php

namespace Tests\Feature\Validation;

use App\Enums\CabinClass;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Services\FlightScheduleService;
use App\Support\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManagerRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->actingAsRole('manager');
    }

    public function test_aircraft_cannot_fly_overlapping_flights(): void
    {
        $aircraft = Aircraft::factory()->create();
        Seat::factory()->for($aircraft)->count(6)->create();

        $dep = now()->addDays(1)->setTime(10, 0);
        Flight::factory()->create([
            'aircraft_id' => $aircraft->id,
            'flight_no' => $this->flightNo('101'),
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => $dep,
            'arrives_at' => $dep->copy()->addHours(2),
            'checkin_opens_at' => $dep->copy()->subHours(3),
            'checkin_closes_at' => $dep->copy()->subHour(),
        ]);

        $turnaround = Limits::minTurnaroundMinutes();
        $tooSoon = $dep->copy()->addHours(2)->addMinutes($turnaround - 5);

        $this->from(route('manager.flights.create'))
            ->post(route('manager.flights.store'), $this->flightPayload($aircraft, $tooSoon, $tooSoon->copy()->addHours(2)))
            ->assertSessionHasErrors('aircraft_id');

        $okDep = $dep->copy()->addHours(2)->addMinutes($turnaround + 5);
        $this->post(route('manager.flights.store'), $this->flightPayload($aircraft, $okDep, $okDep->copy()->addHours(2)))
            ->assertRedirect();

        $this->assertSame(2, Flight::where('aircraft_id', $aircraft->id)->count());
    }

    public function test_cannot_move_flight_to_aircraft_with_fewer_cabin_seats_than_sold(): void
    {
        $big = Aircraft::factory()->create();
        Seat::factory()->for($big)->count(4)->sequence(
            ['seat_no' => '1A', 'cabin_class' => CabinClass::Economy],
            ['seat_no' => '1B', 'cabin_class' => CabinClass::Economy],
            ['seat_no' => '1C', 'cabin_class' => CabinClass::Economy],
            ['seat_no' => '1D', 'cabin_class' => CabinClass::Economy],
        )->create();

        $small = Aircraft::factory()->create();
        Seat::factory()->for($small)->count(1)->create([
            'seat_no' => '1A',
            'cabin_class' => CabinClass::Economy,
        ]);

        $flight = Flight::factory()->create(['aircraft_id' => $big->id]);
        $reservation = Reservation::factory()->for($flight)->create();
        $passengers = Passenger::factory()->for($reservation)->count(2)->create();
        foreach ($passengers as $i => $passenger) {
            SeatAssignment::create([
                'flight_id' => $flight->id,
                'seat_id' => $big->seats[$i]->id,
                'passenger_id' => $passenger->id,
            ]);
        }

        try {
            app(FlightScheduleService::class)->assertAircraftFitsSold($flight, $small);
            $this->fail('Should refuse smaller aircraft');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('aircraft_id', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $flight->update(['aircraft_id' => $small->id]);
    }

    public function test_aircraft_total_seats_cannot_exceed_limit(): void
    {
        $max = Limits::maxAircraftSeats();

        $this->post(route('manager.aircraft.store'), [
            'registration' => 'OD-OVER',
            'model' => 'Too Big',
            'rows' => 50,
            'seats_per_row' => 10,
            'business_rows' => 0,
            'premium_rows' => 0,
        ])->assertSessionHasErrors('rows');

        $this->assertGreaterThan(220, 50 * 10);
        $this->assertSame(220, $max);

        $this->post(route('manager.aircraft.store'), [
            'registration' => 'OD-OK1',
            'model' => 'Fine',
            'rows' => 20,
            'seats_per_row' => 6,
            'business_rows' => 0,
            'premium_rows' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('aircraft', ['registration' => 'OD-OK1']);
    }

    /**
     * @return array<string, mixed>
     */
    private function flightPayload(Aircraft $aircraft, Carbon $departure, Carbon $arrival): array
    {
        $originZone = 'Asia/Beirut';

        return [
            'flight_no' => $this->flightNo((string) random_int(200, 899)),
            'origin' => 'BEY',
            'destination' => 'IST',
            'aircraft_id' => $aircraft->id,
            'departure_at' => $departure->timezone($originZone)->format('Y-m-d\TH:i'),
            'arrives_at' => $arrival->timezone('Europe/Istanbul')->format('Y-m-d\TH:i'),
            'checkin_opens_at' => $departure->copy()->subHours(3)->timezone($originZone)->format('Y-m-d\TH:i'),
            'checkin_closes_at' => $departure->copy()->subHour()->timezone($originZone)->format('Y-m-d\TH:i'),
            'gate' => 'B4',
        ];
    }
}
