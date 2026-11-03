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

class G2BoardingTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->makeAgents();
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME305']);
    }

    private function passengerWithPnr(string $pnr, ?Flight $flight = null): Passenger
    {
        $flight ??= $this->flight;
        $reservation = Reservation::factory()->for($flight)->create(['pnr' => $pnr]);

        return $this->passengerOn($flight, $reservation, ['first_name' => 'Rami', 'last_name' => 'Haddad']);
    }

    private function boardPnr(string $pnr, ?Passenger $choice = null)
    {
        return $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.board', $this->flight), array_filter([
                'pnr' => $pnr,
                'passenger_id' => $choice?->id,
            ]));
    }

    public function test_SR10_a_booked_passenger_is_refused_and_a_checked_in_one_boards(): void
    {
        $booked = $this->passengerWithPnr('BOOK22');
        $ready = $this->passengerWithPnr('REDY22');
        $this->checkIn($ready);
        $this->startBoarding($this->flight);

        $this->boardPnr('BOOK22')
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('error', 'Passenger not checked in');
        $this->assertSame(PassengerStatus::Booked, $booked->fresh()->status);

        $seat = $ready->seatAssignment->seat->seat_no;
        $this->boardPnr('redy22') // the PNR box is not case-sensitive
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('success', "Rami Haddad boarded, seat {$seat}.");
        $this->assertSame(PassengerStatus::Boarded, $ready->fresh()->status);
        $this->assertTrue($ready->fresh()->boardedBy->user->is($this->gateAgent));

        // The counts update.
        $this->getJson(route('gate.flights.counts', $this->flight))
            ->assertJson(['checked_in' => 1, 'boarded' => 1, 'missing' => 0]);
    }

    public function test_SR10_boarding_is_refused_when_the_flight_is_not_boarding(): void
    {
        $passenger = $this->passengerWithPnr('REDY22');
        $this->checkIn($passenger); // flight still CheckinOpen

        $this->boardPnr('REDY22')->assertSessionHas('error', 'Boarding has not started');
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }

    public function test_a_pnr_from_another_flight_is_refused(): void
    {
        $other = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME999']);
        $stranger = $this->passengerWithPnr('OTHR22', $other);
        $this->checkIn($stranger);
        $this->startBoarding($this->flight);

        $this->boardPnr('OTHR22')
            ->assertSessionHas('error', 'PNR OTHR22 is booked on ME999, not on this flight.');
        $this->assertSame(PassengerStatus::CheckedIn, $stranger->fresh()->status);

        $this->boardPnr('ZZZZZZ')->assertSessionHas('error', 'No reservation with PNR ZZZZZZ.');
    }

    public function test_with_two_passengers_on_one_pnr_the_agent_chooses_and_only_that_one_boards(): void
    {
        $first = $this->passengerWithPnr('PAIR22');
        $second = $this->passengerOn($this->flight, $first->reservation, ['first_name' => 'Lina', 'last_name' => 'Haddad']);
        $this->checkIn($first, $second);
        $this->startBoarding($this->flight);

        // Only the PNR: nobody is boarded yet, the agent is asked to choose.
        $this->boardPnr('PAIR22')
            ->assertRedirect(route('gate.flights.show', [$this->flight, 'pnr' => 'PAIR22']));
        $this->assertSame(PassengerStatus::CheckedIn, $first->fresh()->status);
        $this->assertSame(PassengerStatus::CheckedIn, $second->fresh()->status);

        $this->get(route('gate.flights.show', [$this->flight, 'pnr' => 'PAIR22']))
            ->assertOk()
            ->assertSee('Choose the passenger for PNR PAIR22')
            ->assertSee('value="'.$first->id.'"', false)
            ->assertSee('value="'.$second->id.'"', false);

        $this->boardPnr('PAIR22', $second)->assertSessionHas('success');

        $this->assertSame(PassengerStatus::Boarded, $second->fresh()->status);
        $this->assertSame(PassengerStatus::CheckedIn, $first->fresh()->status);
    }

    public function test_a_chosen_passenger_must_be_on_that_pnr(): void
    {
        $passenger = $this->passengerWithPnr('PAIR22');
        $stranger = $this->passengerWithPnr('ELSE22');
        $this->checkIn($passenger, $stranger);
        $this->startBoarding($this->flight);

        $this->boardPnr('PAIR22', $stranger)
            ->assertSessionHas('error', 'That passenger is not on PNR PAIR22.');
        $this->assertSame(PassengerStatus::CheckedIn, $stranger->fresh()->status);
    }

    public function test_the_pnr_box_is_shown_while_boarding(): void
    {
        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertDontSee(route('gate.flights.board', $this->flight), false)
            ->assertSee('Boarding has not started');

        $this->startBoarding($this->flight);

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee(route('gate.flights.board', $this->flight), false)
            ->assertSee('name="pnr"', false);
    }

    public function test_checkin_and_reservation_users_get_403(): void
    {
        $passenger = $this->passengerWithPnr('REDY22');
        $this->checkIn($passenger);
        $this->startBoarding($this->flight);

        foreach (['checkin', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('gate.flights.board', $this->flight), ['pnr' => 'REDY22'])->assertForbidden();
        }

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }

    // --- Start boarding ----------------------------------------------------------------------

    public function test_gate_user_can_start_boarding_after_an_on_page_confirmation(): void
    {
        $startAction = 'action="'.route('gate.flights.start-boarding', $this->flight).'"';

        // Step 1: the button only opens the confirmation.
        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee(route('gate.flights.show', [$this->flight, 'start' => 'confirm']), false)
            ->assertDontSee($startAction, false)
            ->assertDontSee('confirm(', false);

        $this->get(route('gate.flights.show', [$this->flight, 'start' => 'confirm']))
            ->assertOk()
            ->assertSee('Yes, start boarding')
            ->assertSee($startAction, false);
        $this->assertSame(FlightStatus::CheckinOpen, $this->flight->fresh()->status);

        // Step 2: confirm → StatusService::setFlightStatus() moves CheckinOpen → Boarding.
        $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.start-boarding', $this->flight))
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('success', 'Boarding started for ME305.');

        $this->assertSame(FlightStatus::Boarding, $this->flight->fresh()->status);

        // The PNR box appears; the start button is gone.
        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('name="pnr"', false)
            ->assertDontSee(route('gate.flights.show', [$this->flight, 'start' => 'confirm']), false);
    }

    public function test_the_start_boarding_button_is_hidden_for_any_other_status(): void
    {
        $startLink = fn (Flight $flight) => route('gate.flights.show', [$flight, 'start' => 'confirm']);

        foreach ([FlightStatus::Scheduled, FlightStatus::Boarding, FlightStatus::Delayed, FlightStatus::Closed, FlightStatus::Cancelled] as $status) {
            $flight = $this->flightWithStatus($status);

            $this->get(route('gate.flights.show', [$flight, 'start' => 'confirm']))
                ->assertOk()
                ->assertDontSee($startLink($flight), false)
                ->assertDontSee('Yes, start boarding');
        }
    }

    public function test_an_illegal_move_is_refused_with_the_service_message_in_red(): void
    {
        $scheduled = $this->flightWithStatus(FlightStatus::Scheduled);

        $this->from(route('gate.flights.show', $scheduled))
            ->post(route('gate.flights.start-boarding', $scheduled))
            ->assertRedirect(route('gate.flights.show', $scheduled))
            ->assertSessionHas('error', 'Flight cannot go from Scheduled to Boarding');
        $this->assertSame(FlightStatus::Scheduled, $scheduled->fresh()->status);

        $this->followingRedirects()
            ->post(route('gate.flights.start-boarding', $scheduled))
            ->assertSee('role="alert"', false)
            ->assertSee('Flight cannot go from Scheduled to Boarding');
    }

    public function test_only_gate_users_can_start_boarding(): void
    {
        foreach (['checkin', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('gate.flights.start-boarding', $this->flight))->assertForbidden();
        }

        $this->assertSame(FlightStatus::CheckinOpen, $this->flight->fresh()->status);
    }
}
