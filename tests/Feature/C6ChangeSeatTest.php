<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C6ChangeSeatTest extends TestCase
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

        foreach (['2A' => CabinClass::Business, '14C' => CabinClass::Economy, '20A' => CabinClass::Economy, '21A' => CabinClass::Economy] as $no => $cabin) {
            $this->seats[$no] = Seat::factory()->create([
                'aircraft_id' => $this->flight->aircraft_id,
                'seat_no' => $no,
                'cabin_class' => $cabin,
            ]);
        }
    }

    private function booked(string $seatNo = '14C'): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger, $seatNo);

        return $passenger;
    }

    /**
     * Checked in through the screen, so the passenger has an active boarding pass.
     */
    private function checkedIn(string $seatNo = '14C'): Passenger
    {
        $passenger = $this->booked($seatNo);
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        return $passenger->fresh();
    }

    private function change(Passenger $passenger, string $seatNo)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.seats.change', $passenger), ['seat_id' => $this->seats[$seatNo]->id]);
    }

    private function seatOf(Passenger $passenger): ?string
    {
        return $passenger->fresh()->seatAssignment?->seat->seat_no;
    }

    public function test_SR20_economy_passenger_moved_from_14C_to_20A_frees_14C(): void
    {
        $passenger = $this->checkedIn('14C');

        $this->change($passenger, '20A')
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success', 'Seat changed, new boarding pass ready');

        $this->assertSame('20A', $this->seatOf($passenger));
        $this->assertDatabaseMissing('seat_assignments', ['seat_id' => $this->seats['14C']->id]);
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);

        // 14C is free for someone else.
        $this->post(route('checkin.seats.assign', $this->booked('21A')), ['seat_id' => $this->seats['14C']->id])
            ->assertSessionHas('success');
    }

    public function test_SR20_a_move_to_business_is_refused(): void
    {
        $passenger = $this->checkedIn('14C');
        $pass = $passenger->activeBoardingPass;

        $this->change($passenger, '2A')
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Different cabin class');

        $this->assertSame('14C', $this->seatOf($passenger));
        $this->assertNull($pass->fresh()->voided_at);
    }

    public function test_SR20_after_boarding_closes_the_change_is_refused(): void
    {
        $statuses = app(StatusService::class);
        $gate = User::factory()->create(['role' => Role::Gate]);
        $noShow = $this->checkedIn('14C');
        $boarded = $this->checkedIn('21A');

        $statuses->setFlightStatus($this->flight, FlightStatus::Boarding, $gate);
        $statuses->board($boarded, $gate);
        $statuses->closeBoarding($this->flight, $gate);

        foreach ([$noShow, $boarded] as $passenger) {
            $before = $this->seatOf($passenger);

            $this->change($passenger, '20A')->assertSessionHas('error', 'Boarding is closed for this flight.');

            $this->assertSame($before, $this->seatOf($passenger));
            $this->get(route('checkin.passengers.show', $passenger))
                ->assertDontSee('Change seat');
        }
    }

    public function test_SR20_the_change_is_refused_when_the_flight_is_cancelled(): void
    {
        $passenger = $this->checkedIn('14C');
        $pass = $passenger->activeBoardingPass;
        app(StatusService::class)->setFlightStatus($this->flight, FlightStatus::Cancelled, $this->agent);

        $this->change($passenger, '20A')
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'This flight is cancelled.');

        $this->assertSame('14C', $this->seatOf($passenger));
        $this->assertNull($pass->fresh()->voided_at);

        $this->get(route('checkin.passengers.show', [$passenger, 'change_seat' => 1]))
            ->assertOk()
            ->assertDontSee('Change seat')
            ->assertDontSee('id="seat-map"', false);
    }

    public function test_SR20_a_taken_seat_is_refused_and_nothing_changes(): void
    {
        $passenger = $this->checkedIn('14C');
        $pass = $passenger->activeBoardingPass;
        $this->booked('20A');
        $auditRows = AuditEntry::count();

        $this->change($passenger, '20A')->assertSessionHas('error', 'Seat taken');

        // Same transaction: the old seat and the old pass are kept.
        $this->assertSame('14C', $this->seatOf($passenger));
        $this->assertNull($pass->fresh()->voided_at);
        $this->assertTrue($passenger->fresh()->activeBoardingPass->is($pass));
        $this->assertSame($auditRows, AuditEntry::count());
    }

    public function test_SR20_the_old_pass_is_voided_and_the_new_one_shows_20A(): void
    {
        $passenger = $this->checkedIn('14C');
        $old = $passenger->activeBoardingPass;

        $this->get(route('checkin.boarding-pass.show', $passenger))->assertSee('14C');

        $this->change($passenger, '20A')->assertSessionHas('success');

        $this->assertTrue($old->fresh()->voided_at->equalTo(now()));
        $this->assertSame(1, $passenger->boardingPasses()->whereNull('voided_at')->count());
        $this->assertNotSame($old->barcode, $passenger->fresh()->activeBoardingPass->barcode);

        $this->get(route('checkin.boarding-pass.show', $passenger))
            ->assertOk()
            ->assertSee('20A')
            ->assertDontSee('14C');
    }

    public function test_SR20_the_change_writes_an_audit_row(): void
    {
        $passenger = $this->checkedIn('14C');

        $this->change($passenger, '20A');

        $entry = AuditEntry::where('field', 'seat')->sole();
        $this->assertSame($passenger->id, $entry->passenger_id);
        $this->assertSame('14C', $entry->old_value);
        $this->assertSame('20A', $entry->new_value);
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));
    }

    public function test_only_a_checked_in_passenger_can_change_seat(): void
    {
        $booked = $this->booked('14C');
        $this->change($booked, '20A')->assertSessionHas('error', 'The seat can only be changed for a checked-in passenger.');
        $this->assertSame('14C', $this->seatOf($booked));

        app(StatusService::class)->cancel($booked, User::factory()->create(['role' => Role::Reservation]));
        $this->change($booked, '20A')->assertSessionHas('error', 'The seat can only be changed for a checked-in passenger.');
        $this->assertNull($this->seatOf($booked));
    }

    public function test_choosing_the_current_seat_changes_nothing(): void
    {
        $passenger = $this->checkedIn('14C');
        $pass = $passenger->activeBoardingPass;

        $this->change($passenger, '14C')->assertSessionHas('error', 'That is already the passenger\'s seat.');

        $this->assertNull($pass->fresh()->voided_at);
    }

    public function test_a_checked_in_passenger_cannot_bypass_the_rules_with_the_assign_route(): void
    {
        $passenger = $this->checkedIn('14C');

        $this->post(route('checkin.seats.assign', $passenger), ['seat_id' => $this->seats['2A']->id])
            ->assertSessionHas('error', 'Use Change seat for a checked-in passenger.');

        $this->assertSame('14C', $this->seatOf($passenger));
    }

    public function test_change_seat_opens_the_shared_map_with_the_current_seat_selected(): void
    {
        $passenger = $this->checkedIn('14C');
        $link = route('checkin.passengers.show', [$passenger, 'change_seat' => 1]);

        // The map stays closed until the agent asks for it.
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Change seat')
            ->assertSee($link, false)
            ->assertDontSee('id="seat-map"', false);

        $this->get($link)
            ->assertOk()
            ->assertSee('id="seat-map"', false)
            ->assertSee('data-selected-seat="'.$this->seats['14C']->id.'"', false)
            ->assertSee('data-cabin="Economy"', false)
            ->assertSee(route('checkin.seats.change', $passenger), false);

        // A Booked passenger still picks a seat directly (C3), with no "Change seat" action.
        $this->get(route('checkin.passengers.show', [$this->booked('21A'), 'change_seat' => 1]))
            ->assertOk()
            ->assertDontSee('Change seat')
            ->assertDontSee(route('checkin.seats.change', $passenger), false);
    }

    public function test_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->checkedIn('14C');

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('checkin.seats.change', $passenger), ['seat_id' => $this->seats['20A']->id])->assertForbidden();
        }

        $this->assertSame('14C', $this->seatOf($passenger));
    }
}
