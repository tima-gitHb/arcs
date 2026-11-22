<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\Aircraft;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B3ModelsTest extends TestCase
{
    use RefreshDatabase;

    private function makeChain(): array
    {
        $user = User::create([
            'name' => 'Res Agent',
            'email' => 'res@arcs.test',
            'password' => 'secret123',
            'role' => Role::Reservation,
        ]);

        $aircraft = Aircraft::create(['registration' => 'OD-MRL', 'model' => 'A320']);

        $seat = Seat::create([
            'aircraft_id' => $aircraft->id,
            'seat_no' => '12A',
            'cabin_class' => CabinClass::Economy,
        ]);

        $flight = Flight::create([
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'aircraft_id' => $aircraft->id,
            'departure_at' => now()->addHours(5),
            'checkin_opens_at' => now()->addHours(2),
            'checkin_closes_at' => now()->addHours(4),
        ]);

        $reservation = Reservation::create([
            'pnr' => 'ABC234',
            'flight_id' => $flight->id,
            'created_by' => $user->id,
        ]);

        $passenger = Passenger::create([
            'reservation_id' => $reservation->id,
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);

        $bag = Bag::create([
            'passenger_id' => $passenger->id,
            'tag_no' => $this->flightNo('000001'),
            'weight_kg' => 18.5,
        ]);

        return compact('user', 'aircraft', 'seat', 'flight', 'reservation', 'passenger', 'bag');
    }

    public function test_models_and_relationships_read_back(): void
    {
        ['user' => $user, 'aircraft' => $aircraft, 'seat' => $seat, 'flight' => $flight,
            'reservation' => $reservation, 'passenger' => $passenger, 'bag' => $bag] = $this->makeChain();

        $passenger = Passenger::find($passenger->id);
        $flight = Flight::find($flight->id);

        $this->assertSame(Role::Reservation, $user->fresh()->role);
        $this->assertTrue($aircraft->seats->contains($seat));
        $this->assertTrue($aircraft->flights->contains($flight));
        $this->assertTrue($seat->aircraft->is($aircraft));
        $this->assertSame(CabinClass::Economy, $seat->fresh()->cabin_class);
        $this->assertTrue($flight->aircraft->is($aircraft));
        $this->assertTrue($flight->reservations->contains($reservation));
        $this->assertTrue($flight->passengers->contains($passenger));
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        $this->assertTrue($reservation->flight->is($flight));
        $this->assertTrue($reservation->creator->is($user));
        $this->assertTrue($reservation->passengers->contains($passenger));
        $this->assertTrue($user->createdReservations->contains($reservation));
        $this->assertTrue($passenger->reservation->is($reservation));
        $this->assertTrue($passenger->bags->contains($bag));
        $this->assertTrue($bag->passenger->is($passenger));
        $this->assertSame('18.5', $bag->fresh()->weight_kg);
        $this->assertFalse($bag->fresh()->over_allowance);

        $this->assertInstanceOf(PassengerStatus::class, $passenger->status);
        $this->assertSame(PassengerStatus::Booked, $passenger->status);
        $this->assertSame(PassengerType::Adult, $passenger->type);
    }

    public function test_infant_links_to_accompanying_adult(): void
    {
        ['reservation' => $reservation, 'passenger' => $adult] = $this->makeChain();

        $infant = Passenger::create([
            'reservation_id' => $reservation->id,
            'first_name' => 'Lea',
            'last_name' => 'Haddad',
            'type' => PassengerType::Infant,
            'accompanying_adult_id' => $adult->id,
        ]);

        $this->assertTrue($infant->accompanyingAdult->is($adult));
        $this->assertTrue($adult->infants->contains($infant));
    }

    public function test_mass_assigning_passenger_status_is_ignored(): void
    {
        ['reservation' => $reservation] = $this->makeChain();

        $passenger = Passenger::create([
            'reservation_id' => $reservation->id,
            'first_name' => 'Sami',
            'last_name' => 'Khoury',
            'status' => PassengerStatus::Boarded,
            'checked_in_at' => now(),
            'boarded_at' => now(),
        ]);

        $passenger->refresh();
        $this->assertSame(PassengerStatus::Booked, $passenger->status);
        $this->assertNull($passenger->checked_in_at);
        $this->assertNull($passenger->boarded_at);

        $passenger->update(['status' => PassengerStatus::Cancelled]);
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_mass_assigning_flight_status_and_boarding_closed_at_is_ignored(): void
    {
        ['aircraft' => $aircraft] = $this->makeChain();

        $flight = Flight::create([
            'flight_no' => $this->flightNo('202'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'aircraft_id' => $aircraft->id,
            'departure_at' => now()->addHours(5),
            'checkin_opens_at' => now()->addHours(2),
            'checkin_closes_at' => now()->addHours(4),
            'status' => FlightStatus::Boarding,
            'boarding_closed_at' => now(),
        ]);

        $flight->refresh();
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        $this->assertNull($flight->boarding_closed_at);

        $flight->update(['status' => FlightStatus::Closed, 'boarding_closed_at' => now()]);
        $flight->refresh();
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        $this->assertNull($flight->boarding_closed_at);
    }
}
