<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C3SeatTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    /** @var array<string, Seat> seat_no => Seat */
    private array $seats = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen);

        foreach (['1A' => CabinClass::Business, '12A' => CabinClass::Economy, '12B' => CabinClass::Economy, '14C' => CabinClass::Economy] as $no => $cabin) {
            $this->seats[$no] = Seat::factory()->create([
                'aircraft_id' => $this->flight->aircraft_id,
                'seat_no' => $no,
                'cabin_class' => $cabin,
            ]);
        }
    }

    private function passenger(PassengerType $type = PassengerType::Adult, ?Passenger $adult = null): Passenger
    {
        $reservation = $adult?->reservation ?? Reservation::factory()->for($this->flight)->create();
        $passenger = Passenger::factory()->for($reservation)->create([
            'type' => $type,
            'accompanying_adult_id' => $adult?->id,
        ]);
        TravelDocument::factory()->for($passenger)->create();

        return $passenger;
    }

    private function assign(Passenger $passenger, string $seatNo)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.seats.assign', $passenger), ['seat_id' => $this->seats[$seatNo]->id]);
    }

    private function seatOf(Passenger $passenger): ?string
    {
        return $passenger->fresh()->seatAssignment?->seat->seat_no;
    }

    public function test_SR7_two_agents_cannot_take_same_seat(): void
    {
        $first = $this->passenger();
        $second = $this->passenger();
        $otherAgent = User::factory()->create(['role' => Role::Checkin]);

        $this->assign($first, '12A')
            ->assertRedirect(route('checkin.passengers.show', $first))
            ->assertSessionHas('success');

        $this->actingAs($otherAgent);
        $this->assign($second, '12A')
            ->assertRedirect(route('checkin.passengers.show', $second))
            ->assertSessionHas('error', 'Seat taken');

        $this->assertSame('12A', $this->seatOf($first));
        $this->assertNull($this->seatOf($second));
        $this->assertSame(1, SeatAssignment::where('seat_id', $this->seats['12A']->id)->count());
    }

    public function test_a_taken_seat_cannot_be_chosen(): void
    {
        $holder = $this->passenger();
        SeatAssignment::create(['flight_id' => $this->flight->id, 'seat_id' => $this->seats['12B']->id, 'passenger_id' => $holder->id]);
        $passenger = $this->passenger();

        $this->assign($passenger, '12B')->assertSessionHas('error', 'Seat taken');

        $this->assertNull($this->seatOf($passenger));
        $this->assertSame('12B', $this->seatOf($holder));
    }

    public function test_a_free_seat_can_be_chosen_and_is_audited(): void
    {
        $passenger = $this->passenger();

        $this->assign($passenger, '14C')->assertSessionHas('success', 'Seat 14C assigned.');

        $this->assertSame('14C', $this->seatOf($passenger));
        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id, 'field' => 'seat', 'old_value' => null, 'new_value' => '14C', 'user_id' => $this->agent->id,
        ]);
    }

    public function test_choosing_another_seat_replaces_the_reservation_seat(): void
    {
        $passenger = $this->passenger();
        SeatAssignment::create(['flight_id' => $this->flight->id, 'seat_id' => $this->seats['12A']->id, 'passenger_id' => $passenger->id]);

        // The seat from the reservation is shown as selected.
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('data-selected-seat="'.$this->seats['12A']->id.'"', false)
            ->assertSee('12A');

        $this->assign($passenger, '14C')->assertSessionHas('success');

        $this->assertSame('14C', $this->seatOf($passenger));
        $this->assertSame(1, SeatAssignment::where('passenger_id', $passenger->id)->count());
        $this->assertDatabaseMissing('seat_assignments', ['seat_id' => $this->seats['12A']->id]);
        $this->assertDatabaseHas('audit_log', ['passenger_id' => $passenger->id, 'field' => 'seat', 'old_value' => '12A', 'new_value' => '14C']);

        // The released seat is free for someone else.
        $this->assign($this->passenger(), '12A')->assertSessionHas('success');
    }

    public function test_replacing_with_a_taken_seat_keeps_the_old_seat(): void
    {
        $holder = $this->passenger();
        SeatAssignment::create(['flight_id' => $this->flight->id, 'seat_id' => $this->seats['14C']->id, 'passenger_id' => $holder->id]);
        $passenger = $this->passenger();
        SeatAssignment::create(['flight_id' => $this->flight->id, 'seat_id' => $this->seats['12A']->id, 'passenger_id' => $passenger->id]);

        $this->assign($passenger, '14C')->assertSessionHas('error', 'Seat taken');

        // Same transaction: the old seat was not lost.
        $this->assertSame('12A', $this->seatOf($passenger));
        $this->assertSame(0, AuditEntry::where('field', 'seat')->count());
    }

    public function test_an_infant_cannot_get_a_seat(): void
    {
        $adult = $this->passenger();
        $infant = $this->passenger(PassengerType::Infant, $adult);

        $this->assign($infant, '12A')->assertSessionHas('error', 'An infant has no seat.');
        $this->assertNull($this->seatOf($infant));

        $this->get(route('checkin.passengers.show', $infant))
            ->assertOk()
            ->assertSee('An infant has no seat.')
            ->assertDontSee('id="seat-map"', false);
    }

    public function test_a_seat_from_another_aircraft_is_refused(): void
    {
        $foreign = Seat::factory()->create(['seat_no' => '30F']); // another aircraft
        $passenger = $this->passenger();

        $this->post(route('checkin.seats.assign', $passenger), ['seat_id' => $foreign->id])
            ->assertSessionHas('error', 'That seat is not on this flight.');
        $this->assertNull($this->seatOf($passenger));
    }

    public function test_seat_cannot_change_for_a_cancelled_passenger(): void
    {
        $passenger = $this->passenger();
        app(StatusService::class)->cancel($passenger, User::factory()->create(['role' => Role::Reservation]));

        $this->assign($passenger, '12A')
            ->assertSessionHas('error', 'The seat can only be changed for a booked or checked-in passenger.');
        $this->assertNull($this->seatOf($passenger));
    }

    public function test_screen_shows_the_seat_map_with_taken_seats_blocked(): void
    {
        $holder = $this->passenger();
        SeatAssignment::create(['flight_id' => $this->flight->id, 'seat_id' => $this->seats['12B']->id, 'passenger_id' => $holder->id]);
        $passenger = $this->passenger();

        $response = $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('id="seat-map"', false)
            ->assertSee(route('checkin.seats.assign', $passenger), false);

        preg_match('/data-seats="([^"]*)"/', $response->getContent(), $match);
        $seats = collect(json_decode(html_entity_decode($match[1]), true))->keyBy('seat_no');

        // Same data and row order as the reservation seat map (R1).
        $this->assertSame(['1A', '12A', '12B', '14C'], $seats->keys()->all());
        $this->assertTrue($seats['12B']['taken']);
        $this->assertFalse($seats['12A']['taken']);
        $this->assertSame('Business', $seats['1A']['cabin']);
    }

    public function test_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->passenger();

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('checkin.seats.assign', $passenger), ['seat_id' => $this->seats['12A']->id])->assertForbidden();
        }

        $this->assertNull($this->seatOf($passenger));
    }
}
