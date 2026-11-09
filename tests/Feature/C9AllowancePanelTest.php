<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C9AllowancePanelTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME301']);
        $this->seedBookingPrices();
    }

    private function passenger(array $attributes = [], CabinClass $seatClass = CabinClass::Economy): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create($attributes);
        TravelDocument::factory()->for($passenger)->create();

        $seat = Seat::factory()->create([
            'aircraft_id' => $this->flight->aircraft_id,
            'cabin_class' => $seatClass,
        ]);
        SeatAssignment::create([
            'flight_id' => $this->flight->id,
            'seat_id' => $seat->id,
            'passenger_id' => $passenger->id,
        ]);

        return $passenger->fresh();
    }

    private function addBag(Passenger $passenger, float $kg, BagType $type = BagType::Standard)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.bags.store', $passenger), ['weight_kg' => $kg, 'type' => $type->value]);
    }

    public function test_SR58_economy_with_one_extra_bag_shows_3_x_23_kg_and_69_kg_total(): void
    {
        $passenger = $this->passenger(['cabin_class' => CabinClass::Economy, 'extra_bags' => 1]);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Economy')
            ->assertSee('3 × 23 kg', false)
            ->assertSee('69 kg total', false)
            ->assertSee('1 extra bag', false)
            ->assertSee('paid at booking', false);
    }

    public function test_SR58_business_allowance_shows_2_x_32_kg(): void
    {
        $passenger = $this->passenger(['cabin_class' => CabinClass::Business], CabinClass::Business);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Business')
            ->assertSee('2 × 32 kg', false);
    }

    public function test_SR58_infant_uses_the_accompanying_adults_class(): void
    {
        $adult = $this->passenger(['cabin_class' => CabinClass::Business], CabinClass::Business);
        $infant = Passenger::factory()->for($adult->reservation)->create([
            'type' => PassengerType::Infant,
            'accompanying_adult_id' => $adult->id,
            'cabin_class' => CabinClass::Economy,
        ]);

        $this->get(route('checkin.passengers.show', $infant))
            ->assertOk()
            ->assertSee('Business')
            ->assertSee('2 × 32 kg', false);
    }

    public function test_SR58_used_pieces_and_kg_update_after_adding_a_bag(): void
    {
        $passenger = $this->passenger(['cabin_class' => CabinClass::Economy]);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Used: 0 pieces / 0 kg', false);

        $this->addBag($passenger, 20)->assertSessionHas('success');
        $this->addBag($passenger, 12, BagType::Wheelchair)->assertSessionHas('success');

        $this->get(route('checkin.passengers.show', $passenger->fresh()))
            ->assertOk()
            ->assertSee('Used: 1 piece / 20 kg', false);
    }
}
