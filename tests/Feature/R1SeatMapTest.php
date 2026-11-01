<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R1SeatMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_seat_map_marks_taken_seats_and_orders_rows_numerically(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();

        $rowTwo = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '2A',
            'cabin_class' => CabinClass::Business,
        ]);
        $rowTen = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '10A',
            'cabin_class' => CabinClass::Economy,
        ]);

        $reservation = Reservation::factory()->for($flight)->create();
        $passenger = Passenger::factory()->for($reservation)->create();
        SeatAssignment::create([
            'flight_id' => $flight->id,
            'seat_id' => $rowTen->id,
            'passenger_id' => $passenger->id,
        ]);

        $seats = $this->getJson(route('reservation.seats', $flight))
            ->assertOk()
            ->json('seats');

        $this->assertSame(['2A', '10A'], array_column($seats, 'seat_no'));
        $this->assertFalse($seats[0]['taken']);
        $this->assertSame(2, $seats[0]['row']);
        $this->assertSame('A', $seats[0]['letter']);
        $this->assertSame('Business', $seats[0]['cabin']);
        $this->assertTrue($seats[1]['taken']);
        $this->assertSame($rowTwo->id, $seats[0]['id']);

        $departed = $this->flightWithStatus(FlightStatus::Departed);
        $this->getJson(route('reservation.seats', $departed))->assertNotFound();

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('Seat map');
    }

    public function test_SR7_two_bookings_cannot_take_the_same_seat(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $taken = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12A',
        ]);
        $free = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12B',
        ]);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($taken->id),
            ],
        ])->assertRedirect();

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($taken->id, 'Maya'),
            ],
        ])->assertSessionHasErrors([
            'passengers.0.seat_id' => 'Seat taken',
        ]);

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, SeatAssignment::query()->where('seat_id', $taken->id)->count());
        $this->assertSame(0, SeatAssignment::query()->where('seat_id', $free->id)->count());
    }

    public function test_two_passengers_on_one_reservation_cannot_share_a_seat(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '14C',
        ]);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seat->id),
                $this->passenger($seat->id, 'Maya'),
            ],
        ])->assertSessionHasErrors([
            'passengers.1.seat_id' => 'Seat taken',
        ]);

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, SeatAssignment::count());
    }

    public function test_an_infant_cannot_be_given_a_seat(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $adultSeat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '4A',
        ]);
        $infantSeat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '4B',
        ]);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($adultSeat->id),
                [
                    'first_name' => 'Lea',
                    'last_name' => 'Haddad',
                    'type' => 'Infant',
                    'date_of_birth' => '2025-06-01',
                    'accompanying_adult' => 0,
                    'seat_id' => $infantSeat->id,
                ],
            ],
        ])->assertSessionHasErrors([
            'passengers.1.seat_id' => 'An infant does not get a seat.',
        ]);

        $this->assertSame(0, Reservation::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function passenger(int $seatId, string $first = 'Rami'): array
    {
        return [
            'first_name' => $first,
            'last_name' => 'Haddad',
            'type' => 'Adult',
            'date_of_birth' => '1985-04-12',
            'seat_id' => $seatId,
        ];
    }
}
