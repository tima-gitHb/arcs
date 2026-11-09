<?php

namespace Tests\Feature\Gate;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class G6ScanBoardingPassTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->makeAgents();
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME405']);
    }

    private function passengerWithPnr(string $pnr, ?Flight $flight = null, array $attributes = []): Passenger
    {
        $flight ??= $this->flight;
        $reservation = Reservation::factory()->for($flight)->create(['pnr' => $pnr]);

        return $this->passengerOn($flight, $reservation, $attributes + [
            'first_name' => 'Rami', 'last_name' => 'Haddad',
        ]);
    }

    public function test_SR62_scanner_value_with_spaces_and_newline_boards_the_right_passenger(): void
    {
        $passenger = $this->passengerWithPnr('QRXT62');
        $this->checkIn($passenger);
        $this->startBoarding($this->flight);
        $seat = $passenger->seatAssignment->seat->seat_no;

        $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.board', $this->flight), ['pnr' => " qrxt62\n"])
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('success', "Rami Haddad boarded, seat {$seat}.");

        $this->assertSame(PassengerStatus::Boarded, $passenger->fresh()->status);

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('Rami Haddad', false)
            ->assertSee($seat, false)
            ->assertSee('boarded-ok', false);
    }

    public function test_SR62_another_flights_pass_is_refused(): void
    {
        $other = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME406']);
        $passenger = $this->passengerWithPnr('OTHR22', $other);
        $this->checkIn($passenger);
        $this->startBoarding($this->flight);

        $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.board', $this->flight), ['pnr' => " othr22\r\n"])
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('error');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('boarded-fail', false);
    }

    public function test_SR62_a_shared_pnr_shows_the_chooser(): void
    {
        $reservation = Reservation::factory()->for($this->flight)->create(['pnr' => 'PAIR62']);
        $first = $this->passengerOn($this->flight, $reservation, ['first_name' => 'Rami', 'last_name' => 'Haddad']);
        $second = $this->passengerOn($this->flight, $reservation, ['first_name' => 'Lara', 'last_name' => 'Haddad']);
        $this->checkIn($first, $second);
        $this->startBoarding($this->flight);

        $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.board', $this->flight), ['pnr' => " pair62\n"])
            ->assertRedirect(route('gate.flights.show', [$this->flight, 'pnr' => 'PAIR62']));

        $this->get(route('gate.flights.show', [$this->flight, 'pnr' => 'PAIR62']))
            ->assertOk()
            ->assertSee('Choose the passenger for PNR PAIR62', false)
            ->assertSee('Rami Haddad')
            ->assertSee('Lara Haddad');
    }

    public function test_SR62_the_pnr_box_has_autofocus(): void
    {
        $this->startBoarding($this->flight);

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('id="pnr"', false)
            ->assertSee('autofocus', false);
    }
}
