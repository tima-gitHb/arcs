<?php

namespace Tests\Feature;

use App\Enums\PassengerStatus;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R3CancelPassengerTest extends TestCase
{
    use RefreshDatabase;

    public function test_SR5_cancelling_a_booked_passenger_frees_the_seat(): void
    {
        $agent = $this->actingAsRole('reservation');
        $reservation = Reservation::factory()->create();
        $flight = $reservation->flight;
        $seat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12A',
        ]);
        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);
        SeatAssignment::create([
            'flight_id' => $flight->id,
            'seat_id' => $seat->id,
            'passenger_id' => $passenger->id,
        ]);

        $this->get(route('reservation.confirmation', $reservation))
            ->assertOk()
            ->assertSee('Cancel')
            ->assertSee('Are you sure?');

        $this->get(route('reservation.search', ['flight' => $flight->id]))
            ->assertOk()
            ->assertSee('Rami')
            ->assertSee('Cancel');

        $response = $this->post(route('reservation.cancel', $passenger));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Rami Haddad cancelled.');

        $passenger->refresh();
        $this->assertSame(PassengerStatus::Cancelled, $passenger->status);
        $this->assertSame($agent->id, $passenger->cancelledBy->user_id);
        $this->assertDatabaseMissing('seat_assignments', ['passenger_id' => $passenger->id]);

        $this->get(route('reservation.confirmation', $reservation))
            ->assertOk()
            ->assertSee('Cancelled')
            ->assertSee('Not assigned')
            ->assertDontSee('Are you sure?');

        $other = Passenger::factory()->for($reservation)->create();
        SeatAssignment::create([
            'flight_id' => $flight->id,
            'seat_id' => $seat->id,
            'passenger_id' => $other->id,
        ]);
        $this->assertDatabaseHas('seat_assignments', [
            'seat_id' => $seat->id,
            'passenger_id' => $other->id,
        ]);
    }

    public function test_cancelling_someone_who_is_not_booked_shows_the_reason(): void
    {
        $this->actingAsRole('reservation');
        $reservation = Reservation::factory()->create();
        $passenger = Passenger::factory()->for($reservation)->create();

        $this->post(route('reservation.cancel', $passenger))->assertRedirect();

        $this->post(route('reservation.cancel', $passenger))
            ->assertRedirect()
            ->assertSessionHas('error', 'Only a booked passenger can be cancelled');

        $this->assertSame(PassengerStatus::Cancelled, $passenger->fresh()->status);
    }
}
