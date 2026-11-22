<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R2FindReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_by_pnr_shows_the_reservation_and_passengers(): void
    {
        $this->actingAsRole('reservation');

        $reservation = Reservation::factory()->create(['pnr' => 'K7M2NP']);
        Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);

        $this->get(route('reservation.search', ['pnr' => 'k7m2np']))
            ->assertOk()
            ->assertSee('Find reservation')
            ->assertSee('K7M2NP')
            ->assertSee('Rami')
            ->assertSee('Haddad')
            ->assertSee('Booked');
    }

    public function test_unknown_pnr_is_not_found(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.search', ['pnr' => 'ZZZZZZ']))
            ->assertOk()
            ->assertSee('No reservation with that PNR.');
    }

    public function test_choosing_a_flight_shows_only_that_flights_reservations(): void
    {
        $this->actingAsRole('reservation');

        $paris = Flight::factory()->create([
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        $rome = Flight::factory()->create([
            'flight_no' => $this->flightNo('422'),
            'origin' => 'BEY',
            'destination' => 'FCO',
        ]);

        $first = Reservation::factory()->for($paris)->create(['pnr' => 'PARIS2']);
        Passenger::factory()->for($first)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);
        $second = Reservation::factory()->for($paris)->create(['pnr' => 'PARIS3']);
        Passenger::factory()->for($second)->create([
            'first_name' => 'Nour',
            'last_name' => 'Haddad',
        ]);
        $onRome = Reservation::factory()->for($rome)->create(['pnr' => 'RO'.$this->flightNo('22')]);
        Passenger::factory()->for($onRome)->create([
            'first_name' => 'Maya',
            'last_name' => 'Khoury',
        ]);

        $this->get(route('reservation.search'))
            ->assertOk()
            ->assertSee($this->flightNo('201'))
            ->assertSee($this->flightNo('422'))
            ->assertSee('Choose a flight to see its reservations.');

        $this->get(route('reservation.search', ['flight' => $paris->id]))
            ->assertOk()
            ->assertSee('Reservations on '.$this->flightNo('201'))
            ->assertSee('PARIS2')
            ->assertSee('PARIS3')
            ->assertSee('Rami')
            ->assertSee('Nour')
            ->assertDontSee('RO'.$this->flightNo('22'))
            ->assertDontSee('Maya');

        $this->get(route('reservation.search', ['flight' => $rome->id]))
            ->assertOk()
            ->assertSee('RO'.$this->flightNo('22'))
            ->assertSee('Maya')
            ->assertDontSee('PARIS2');
    }

    public function test_a_flight_with_no_reservations_says_so(): void
    {
        $this->actingAsRole('reservation');

        $flight = Flight::factory()->create(['flight_no' => $this->flightNo('110')]);

        $this->get(route('reservation.search', ['flight' => $flight->id]))
            ->assertOk()
            ->assertSee($this->flightNo('110'))
            ->assertSee('No reservations on this flight.');
    }

    public function test_many_flights_stay_in_one_filterable_list(): void
    {
        $this->actingAsRole('reservation');

        foreach (range(1, 40) as $number) {
            Flight::factory()->create([
                'flight_no' => $this->flightNo(sprintf('%03d', $number)),
                'destination' => 'CDG',
                'departure_at' => now()->addDays($number)->setTime(8, 0),
            ]);
        }

        $this->get(route('reservation.search'))
            ->assertOk()
            ->assertSee('Filter flights')
            ->assertSee('max-h-80', false)
            ->assertSee($this->flightNo('001'))
            ->assertSee($this->flightNo('040'))
            ->assertSee('Choose a flight to see its reservations.');
    }

    public function test_search_rejects_a_pnr_with_the_wrong_shape(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.search', ['pnr' => 'IO0001']))
            ->assertOk()
            ->assertSee('Enter a 6-character PNR.')
            ->assertDontSee('No reservation with that PNR.');
    }
}
