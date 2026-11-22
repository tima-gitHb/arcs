<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Exceptions\InvalidStatusChange;
use App\Models\BoardingPass;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Picqer\Barcode\Types\TypeCode128;
use Tests\TestCase;

class C5BoardingPassTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        // Window 09:00–11:15, departure 2026-11-10 12:00 → boarding 11:20.
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => $this->flightNo('201'), 'gate' => 'A3']);
    }

    private function passenger(string $pnr = 'K7M2NP', PassengerType $type = PassengerType::Adult, ?Passenger $adult = null): Passenger
    {
        $reservation = $adult?->reservation ?? Reservation::factory()->for($this->flight)->create(['pnr' => $pnr]);
        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => $type,
            'accompanying_adult_id' => $adult?->id,
        ]);
        TravelDocument::factory()->for($passenger)->create();

        return $passenger;
    }

    private function checkedIn(): Passenger
    {
        $passenger = $this->passenger();
        $this->seatPassenger($passenger, '14C');
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        return $passenger->fresh();
    }

    public function test_SR8_seat_bags_and_pass_done_without_leaving_the_check_in_screen(): void
    {
        $passenger = $this->passenger();
        $seat = Seat::factory()->create(['aircraft_id' => $this->flight->aircraft_id, 'seat_no' => '14C']);
        $screen = route('checkin.passengers.show', $passenger);

        $this->get($screen)->assertOk();

        $this->from($screen)->post(route('checkin.seats.assign', $passenger), ['seat_id' => $seat->id])
            ->assertRedirect($screen);
        $this->from($screen)->post(route('checkin.bags.store', $passenger), ['weight_kg' => 18, 'type' => BagType::Standard->value])
            ->assertRedirect($screen);
        $this->from($screen)->post(route('checkin.passengers.confirm', $passenger))
            ->assertRedirect($screen)
            ->assertSessionHas('success');

        $passenger->refresh();
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->status);
        $this->assertSame('14C', $passenger->seatAssignment->seat->seat_no);
        $this->assertCount(1, $passenger->bags);
        $this->assertNotNull($passenger->activeBoardingPass);

        $this->get($screen)
            ->assertOk()
            ->assertSee('Boarding pass')
            ->assertSee(route('checkin.boarding-pass.show', $passenger), false)
            ->assertSee(route('checkin.boarding-pass.reprint', $passenger), false)
            ->assertDontSee('Confirm check-in');
    }

    public function test_confirming_without_a_seat_is_refused(): void
    {
        $passenger = $this->passenger();

        $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.passengers.confirm', $passenger))
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Seat required');

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertSame(0, BoardingPass::count());
    }

    public function test_status_service_refuses_check_in_without_a_seat(): void
    {
        $passenger = $this->passenger();

        try {
            app(StatusService::class)->checkIn($passenger, $this->agent);
            $this->fail('Expected "Seat required".');
        } catch (InvalidStatusChange $exception) {
            $this->assertSame('Seat required', $exception->getMessage());
        }

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_an_infant_completes_check_in_without_a_seat(): void
    {
        $adult = $this->passenger();
        $infant = $this->passenger(type: PassengerType::Infant, adult: $adult);

        $this->post(route('checkin.passengers.confirm', $infant))->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $infant->fresh()->status);
        $this->assertNotNull($infant->fresh()->activeBoardingPass);

        $this->get(route('checkin.boarding-pass.show', $infant))
            ->assertOk()
            ->assertSee('Infant');
    }

    public function test_SR21_the_barcode_encodes_the_pnr(): void
    {
        $passenger = $this->checkedIn();
        $pass = $passenger->activeBoardingPass;

        $this->assertStringStartsWith('K7M2NP-', $pass->barcode);
        $this->assertSame($pass->barcode, (new TypeCode128)->getBarcode($pass->barcode)->getBarcode());

        $this->get(route('checkin.boarding-pass.show', $passenger))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('data-barcode="'.$pass->barcode.'"', false)
            ->assertSee('K7M2NP');
    }

    public function test_the_pass_shows_name_flight_date_seat_gate_boarding_time_and_pnr(): void
    {
        $passenger = $this->checkedIn();

        $this->get(route('checkin.boarding-pass.show', $passenger))
            ->assertOk()
            ->assertSee('HADDAD / RAMI')
            ->assertSee($this->flightNo('201'))
            ->assertSee('BEY')
            ->assertSee('10 Nov 2026')
            ->assertSee('14C')
            ->assertSee('A3')
            ->assertSee('11:20')  // boarding: 40 minutes before the 12:00 departure
            ->assertSee('12:00')
            ->assertSee('K7M2NP');
    }

    public function test_SR32_after_a_reprint_only_the_new_pass_is_active(): void
    {
        $passenger = $this->checkedIn();
        $old = $passenger->activeBoardingPass;

        $this->travelTo(now()->addMinutes(5));
        $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.boarding-pass.reprint', $passenger))
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success');

        $old->refresh();
        $this->assertTrue($old->voided_at->equalTo(now()));

        $active = $passenger->boardingPasses()->whereNull('voided_at')->get();
        $this->assertCount(1, $active);
        $this->assertTrue($active->first()->is($passenger->fresh()->activeBoardingPass));
        $this->assertNotSame($old->barcode, $active->first()->barcode);
        $this->assertSame(2, $passenger->boardingPasses()->count());
    }

    public function test_SR42_the_pass_shows_the_flights_current_gate(): void
    {
        $passenger = $this->checkedIn();
        $this->get(route('checkin.boarding-pass.show', $passenger))->assertSee('A3');

        $this->flight->update(['gate' => 'B7']);
        $this->post(route('checkin.boarding-pass.reprint', $passenger))->assertSessionHas('success');

        $this->get(route('checkin.boarding-pass.show', $passenger))
            ->assertOk()
            ->assertSee('B7')
            ->assertDontSee('A3');
    }

    public function test_reprint_and_pass_page_need_a_checked_in_passenger(): void
    {
        $passenger = $this->passenger();

        $this->post(route('checkin.boarding-pass.reprint', $passenger))
            ->assertSessionHas('error', 'A boarding pass is only issued to a checked-in passenger.');
        $this->assertSame(0, BoardingPass::count());

        $this->get(route('checkin.boarding-pass.show', $passenger))->assertNotFound();
    }

    public function test_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->checkedIn();

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('checkin.boarding-pass.show', $passenger))->assertForbidden();
            $this->post(route('checkin.boarding-pass.reprint', $passenger))->assertForbidden();
        }

        $this->assertSame(1, BoardingPass::count());
    }
}
