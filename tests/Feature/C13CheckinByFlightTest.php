<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C13 — check-in by flight: today's flights open a list of that flight's reservations.
 */
class C13CheckinByFlightTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        $this->seedBookingPrices();
    }

    private function reservationOn(Flight $flight, string $pnr, array $passengers): Reservation
    {
        $reservation = Reservation::factory()->for($flight)->create(['pnr' => $pnr]);

        foreach ($passengers as $attributes) {
            $passenger = Passenger::factory()->for($reservation)->create($attributes);
            TravelDocument::factory()->for($passenger)->create();
            if (($attributes['type'] ?? PassengerType::Adult) !== PassengerType::Infant) {
                $this->seatPassenger($passenger);
            }
        }

        return $reservation->fresh(['passengers']);
    }

    private function checkIn(Passenger $passenger): void
    {
        app(StatusService::class)->checkIn($passenger, $this->agent);
    }

    public function test_SR_checkin_todays_flights_link_to_the_flight_page(): void
    {
        $this->get(route('checkin.home'))
            ->assertOk()
            ->assertSee($this->flightNo('201'))
            ->assertSee(route('checkin.flights.show', $this->flight), false)
            ->assertSee('Check-in list', false);
    }

    public function test_SR_clicking_ME201_lists_every_reservation_with_the_right_counts(): void
    {
        $this->reservationOn($this->flight, 'AAA111', [
            ['first_name' => 'Rami', 'last_name' => 'Abboud', 'type' => PassengerType::Adult],
            ['first_name' => 'Lara', 'last_name' => 'Abboud', 'type' => PassengerType::Adult],
            ['first_name' => 'Omar', 'last_name' => 'Abboud', 'type' => PassengerType::Child],
        ]);
        $checked = $this->reservationOn($this->flight, 'BBB222', [
            ['first_name' => 'Maya', 'last_name' => 'Khoury', 'type' => PassengerType::Adult],
            ['first_name' => 'Joe', 'last_name' => 'Khoury', 'type' => PassengerType::Adult],
        ]);
        $this->checkIn($checked->passengers->firstWhere('first_name', 'Maya'));

        $response = $this->get(route('checkin.flights.show', $this->flight))
            ->assertOk()
            ->assertSee($this->flightNo('201'))
            ->assertSee('AAA111')
            ->assertSee('BBB222')
            ->assertSee('Rami Abboud')
            ->assertSee('Maya Khoury')
            ->assertSee('3 passengers · 0 checked in', false)
            ->assertSee('2 passengers · 1 checked in', false);

        // Sorted by lead last name: Abboud before Khoury.
        $response->assertSeeInOrder(['AAA111', 'BBB222']);
    }

    public function test_SR_clicking_a_reservation_opens_group_check_in_for_that_PNR(): void
    {
        $reservation = $this->reservationOn($this->flight, 'GRP001', [
            ['first_name' => 'Rami', 'last_name' => 'Haddad', 'type' => PassengerType::Adult],
            ['first_name' => 'Lara', 'last_name' => 'Haddad', 'type' => PassengerType::Adult],
        ]);

        $this->get(route('checkin.flights.show', $this->flight))
            ->assertOk()
            ->assertSee(route('checkin.reservations.group', $reservation), false);

        $this->get(route('checkin.reservations.group', $reservation))
            ->assertOk()
            ->assertSee('GRP001')
            ->assertSee('Rami Haddad')
            ->assertSee('Lara Haddad');
    }

    public function test_SR_a_flight_from_another_station_returns_403(): void
    {
        $other = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('900'),
            'origin' => 'IST',
            'destination' => 'CDG',
        ]);

        $this->get(route('checkin.flights.show', $other))->assertForbidden();
    }

    public function test_SR_filter_by_name_and_PNR_works(): void
    {
        $this->reservationOn($this->flight, 'AAA111', [
            ['first_name' => 'Rami', 'last_name' => 'Abboud', 'type' => PassengerType::Adult],
        ]);
        $this->reservationOn($this->flight, 'BBB222', [
            ['first_name' => 'Maya', 'last_name' => 'Khoury', 'type' => PassengerType::Adult],
        ]);

        $this->get(route('checkin.flights.show', [$this->flight, 'q' => 'Khoury']))
            ->assertOk()
            ->assertSee('BBB222')
            ->assertSee('Maya Khoury')
            ->assertDontSee('AAA111')
            ->assertDontSee('Rami Abboud');

        $this->get(route('checkin.flights.show', [$this->flight, 'q' => 'aaa111']))
            ->assertOk()
            ->assertSee('AAA111')
            ->assertDontSee('BBB222');
    }

    public function test_SR_flight_page_runs_a_fixed_number_of_queries(): void
    {
        $small = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => $this->flightNo('100')]);
        $this->reservationOn($small, 'SML001', [
            ['first_name' => 'A', 'last_name' => 'Small', 'type' => PassengerType::Adult],
        ]);

        for ($i = 0; $i < 40; $i++) {
            $this->reservationOn($this->flight, sprintf('BIG%03d', $i), [
                ['first_name' => 'P'.$i, 'last_name' => sprintf('Lead%03d', $i), 'type' => PassengerType::Adult],
                ['first_name' => 'Q'.$i, 'last_name' => sprintf('Lead%03d', $i), 'type' => PassengerType::Adult],
            ]);
        }

        $count = function (Flight $flight): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('checkin.flights.show', $flight))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count($small), $count($this->flight));
    }

    public function test_SR_gate_and_reservation_users_get_403(): void
    {
        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('checkin.flights.show', $this->flight))->assertForbidden();
        }
    }
}
