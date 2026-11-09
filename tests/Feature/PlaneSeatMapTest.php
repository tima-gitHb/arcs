<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Gate\BuildsGateFlights;
use Tests\TestCase;

class PlaneSeatMapTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->seedBookingPrices();
    }

    public function test_reservation_checkin_and_gate_render_the_plane_in_a_sticky_column(): void
    {
        $this->actingAsRole('reservation');
        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('id="seat-map-panel"', false)
            ->assertSee('plane-fuselage', false)
            ->assertSee('seat-map-sticky', false)
            ->assertSee('Economy')
            ->assertSee('Selected')
            ->assertSee('Taken');

        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->actingAsRole('checkin');
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('id="seat-map-panel"', false)
            ->assertSee('plane-fuselage', false)
            ->assertSee('seat-map-sticky', false);

        $this->makeAgents();
        $gateFlight = $this->flightWithStatus(FlightStatus::Boarding, ['flight_no' => 'ME701']);
        $this->get(route('gate.flights.show', $gateFlight))
            ->assertOk()
            ->assertSee('id="seat-map-panel"', false)
            ->assertSee('plane-fuselage', false)
            ->assertSee('seat-map-sticky', false)
            ->assertSee('Checked in')
            ->assertSee('Boarded')
            ->assertSee('No-show');
    }

    public function test_gate_counts_json_returns_seat_states_without_names(): void
    {
        $this->makeAgents();
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME702']);
        $passenger = $this->passengerOn($flight, null, ['first_name' => 'Secret', 'last_name' => 'Name']);
        $this->checkIn($passenger);
        $this->startBoarding($flight);
        $seatNo = $passenger->seatAssignment->seat->seat_no;

        $json = $this->getJson(route('gate.flights.counts', $flight))
            ->assertOk()
            ->assertJsonStructure(['checked_in', 'boarded', 'missing', 'status', 'seats'])
            ->json();

        $this->assertArrayHasKey($seatNo, $json['seats']);
        $this->assertSame('CheckedIn', $json['seats'][$seatNo]);
        $encoded = json_encode($json);
        $this->assertStringNotContainsString('Secret', $encoded);
        $this->assertStringNotContainsString('Name', $encoded);
        $this->assertStringNotContainsString('passport', strtolower($encoded));
    }

    public function test_boarding_a_passenger_updates_that_seat_state_in_the_json(): void
    {
        $this->makeAgents();
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME703']);
        $passenger = $this->passengerOn($flight);
        $this->checkIn($passenger);
        $this->startBoarding($flight);
        $seatNo = $passenger->seatAssignment->seat->seat_no;

        $this->assertSame('CheckedIn', $this->getJson(route('gate.flights.counts', $flight))->json('seats')[$seatNo]);

        $this->post(route('gate.flights.board', $flight), ['pnr' => $passenger->reservation->pnr])
            ->assertSessionHas('success');

        $this->assertSame(PassengerStatus::Boarded, $passenger->fresh()->status);
        $this->assertSame('Boarded', $this->getJson(route('gate.flights.counts', $flight))->json('seats')[$seatNo]);
    }
}
