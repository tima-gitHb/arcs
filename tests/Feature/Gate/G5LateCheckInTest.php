<?php

namespace Tests\Feature\Gate;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Models\AuditEntry;
use App\Models\BoardingPass;
use App\Models\Flight;
use App\Models\GateOverride;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class G5LateCheckInTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    private const REASON = 'Connecting coach delayed, passenger at the gate';

    private Flight $flight;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Check-in window 09:00–11:15, departure 12:00; the flight is already Boarding.
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->makeAgents();
        $this->manager = User::factory()->create(['role' => Role::Manager, 'name' => 'Hiba Daher', 'email' => 'boss@arcs.test']);
        $this->flight = $this->flightWithStatus(FlightStatus::Boarding, ['flight_no' => $this->flightNo('305')]);
    }

    private function late(Passenger $passenger, array $overrides = [])
    {
        return $this->from(route('gate.passengers.late-checkin', $passenger))
            ->post(route('gate.passengers.late-checkin.store', $passenger), $overrides + [
                'reason' => self::REASON,
                'manager_email' => 'boss@arcs.test',
                'password' => 'password',
            ]);
    }

    private function assertNotCheckedIn(Passenger $passenger): void
    {
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertSame(0, GateOverride::count());
        $this->assertSame(0, BoardingPass::count());
    }

    public function test_SR29_a_late_check_in_without_approval_is_refused(): void
    {
        $passenger = $this->passengerOn($this->flight);

        // The normal check-in is closed…
        try {
            app(StatusService::class)->checkIn($passenger, $this->gateAgent);
            $this->fail('Expected "Check-in closed".');
        } catch (InvalidStatusChange $exception) {
            $this->assertSame('Check-in closed', $exception->getMessage());
        }

        // …and the override needs a reason and a Manager's email and password.
        $this->post(route('gate.passengers.late-checkin.store', $passenger), [])
            ->assertSessionHasErrors(['reason', 'manager_email', 'password']);
        $this->late($passenger, ['reason' => '   '])->assertSessionHasErrors('reason');

        $this->assertNotCheckedIn($passenger);
    }

    public function test_SR29_with_a_managers_approval_and_a_reason_it_succeeds(): void
    {
        $passenger = $this->passengerOn($this->flight, null, ['first_name' => 'Rami', 'last_name' => 'Haddad']);

        $this->late($passenger)
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('success', 'Rami Haddad checked in late. Boarding pass issued.');

        $passenger->refresh();
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->status);
        $this->assertTrue($passenger->checked_in_at->equalTo(now()));

        $override = GateOverride::sole();
        $this->assertSame($passenger->id, $override->passenger_id);
        $this->assertSame($this->manager->id, $override->approved_by);
        $this->assertSame(self::REASON, $override->reason);
        $this->assertTrue($override->approved_at->equalTo(now()));

        // The gate agent did it, the Manager only approved.
        $this->assertTrue($passenger->checkedInBy->user->is($this->gateAgent));
        $this->assertSame(1, AuditEntry::where('field', 'status')->where('new_value', 'CheckedIn')->count());

        // Approving does not log the Manager in.
        $this->assertAuthenticatedAs($this->gateAgent);
    }

    public function test_SR29_a_wrong_password_or_unknown_email_is_refused(): void
    {
        $passenger = $this->passengerOn($this->flight);
        $form = route('gate.passengers.late-checkin', $passenger);

        $this->late($passenger, ['password' => 'wrong-password'])
            ->assertRedirect($form)
            ->assertSessionHas('error', 'Manager email or password is incorrect.');
        $this->late($passenger, ['manager_email' => 'nobody@arcs.test'])
            ->assertSessionHas('error', 'Manager email or password is incorrect.');

        $this->assertNotCheckedIn($passenger);
    }

    public function test_SR29_a_check_in_users_credentials_are_refused(): void
    {
        $passenger = $this->passengerOn($this->flight);

        $this->late($passenger, ['manager_email' => $this->checkinAgent->email])
            ->assertSessionHas('error', 'That account is not a Manager.');

        $this->assertNotCheckedIn($passenger);
    }

    public function test_SR29_a_locked_or_deactivated_manager_is_refused(): void
    {
        $passenger = $this->passengerOn($this->flight);

        $this->manager->forceFill(['locked' => true])->save();
        $this->late($passenger)->assertSessionHas('error', 'That Manager account is locked.');

        $this->manager->forceFill(['locked' => false, 'active' => false])->save();
        $this->late($passenger)->assertSessionHas('error', 'That Manager account is deactivated.');

        $this->assertNotCheckedIn($passenger);
    }

    public function test_the_password_is_never_kept_after_a_refusal(): void
    {
        $passenger = $this->passengerOn($this->flight);

        // Refused by the rules, and refused by validation: neither keeps the password.
        $this->late($passenger, ['password' => 'wrong-password'])
            ->assertSessionHas('_old_input.reason', self::REASON)
            ->assertSessionMissing('_old_input.password');
        $this->late($passenger, ['reason' => ''])->assertSessionMissing('_old_input.password');

        $this->get(route('gate.passengers.late-checkin', $passenger))->assertDontSee('wrong-password');
    }

    public function test_password_guessing_is_throttled(): void
    {
        $passenger = $this->passengerOn($this->flight);

        for ($i = 0; $i < 5; $i++) {
            $this->late($passenger, ['password' => 'guess-'.$i])->assertRedirect();
        }

        $this->late($passenger, ['password' => 'guess-6'])->assertTooManyRequests();
    }

    public function test_SR29_an_expired_passport_is_still_refused(): void
    {
        $passenger = $this->passengerOn($this->flight);
        $passenger->travelDocuments()->first()->update(['expiry_date' => '2026-11-01']);

        $this->late($passenger)->assertSessionHas('error', 'Document expired');

        $this->assertNotCheckedIn($passenger);
    }

    public function test_SR29_a_cancelled_or_already_checked_in_passenger_is_still_refused(): void
    {
        $cancelled = $this->passengerOn($this->flight);
        app(StatusService::class)->cancel($cancelled, $this->gateAgent);
        $this->late($cancelled)->assertSessionHas('error', 'Reservation cancelled');

        $done = $this->passengerOn($this->flight);
        $this->late($done)->assertSessionHas('success');
        $this->late($done)->assertSessionHas('error', 'Already checked in');
        $this->assertSame(1, GateOverride::count());
    }

    public function test_SR29_the_passenger_gets_a_seat_and_an_active_pass(): void
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create();
        $passenger->travelDocuments()->create([
            'type' => 'Passport', 'number' => 'LR1234567', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01',
        ]);
        $seat = Seat::factory()->create(['aircraft_id' => $this->flight->aircraft_id, 'seat_no' => '14C']);

        // No seat yet: the form shows the shared seat map.
        $this->get(route('gate.passengers.late-checkin', $passenger))
            ->assertOk()
            ->assertSee('id="seat-map"', false)
            ->assertSee('name="seat_id"', false);

        $this->late($passenger)->assertSessionHas('error', 'Seat required');
        $this->assertNotCheckedIn($passenger);

        $this->late($passenger, ['seat_id' => $seat->id])->assertSessionHas('success');

        $passenger->refresh();
        $this->assertSame('14C', $passenger->seatAssignment->seat->seat_no);
        $this->assertNotNull($passenger->activeBoardingPass);
        $this->assertSame(1, $passenger->boardingPasses()->whereNull('voided_at')->count());
        $this->assertDatabaseHas('audit_log', ['passenger_id' => $passenger->id, 'field' => 'seat', 'new_value' => '14C']);
    }

    public function test_a_refused_override_does_not_keep_the_chosen_seat(): void
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create();
        $passenger->travelDocuments()->create([
            'type' => 'Passport', 'number' => 'LR1234567', 'issuing_country' => 'LB', 'expiry_date' => '2026-11-01',
        ]);
        $seat = Seat::factory()->create(['aircraft_id' => $this->flight->aircraft_id, 'seat_no' => '14C']);

        $this->late($passenger, ['seat_id' => $seat->id])->assertSessionHas('error', 'Document expired');

        $this->assertNull($passenger->fresh()->seatAssignment);
    }

    public function test_a_taken_seat_is_refused(): void
    {
        $holder = $this->passengerOn($this->flight);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create();
        $passenger->travelDocuments()->create([
            'type' => 'Passport', 'number' => 'LR1234567', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01',
        ]);

        $this->late($passenger, ['seat_id' => $holder->seatAssignment->seat_id])
            ->assertSessionHas('error', 'Seat taken');

        $this->assertNotCheckedIn($passenger);
    }

    public function test_a_passenger_with_a_seat_keeps_it_and_the_form_shows_no_map(): void
    {
        $passenger = $this->passengerOn($this->flight);
        $seatNo = $passenger->seatAssignment->seat->seat_no;

        $this->get(route('gate.passengers.late-checkin', $passenger))
            ->assertOk()
            ->assertSee($seatNo)
            ->assertDontSee('id="seat-map"', false);

        $this->late($passenger)->assertSessionHas('success');
        $this->assertSame($seatNo, $passenger->fresh()->seatAssignment->seat->seat_no);
    }

    public function test_an_infant_needs_no_seat(): void
    {
        $adult = $this->passengerOn($this->flight);
        $infant = $this->passengerOn($this->flight, $adult->reservation, [
            'type' => PassengerType::Infant, 'accompanying_adult_id' => $adult->id,
        ]);

        $this->get(route('gate.passengers.late-checkin', $infant))->assertOk()->assertDontSee('id="seat-map"', false);
        $this->late($infant)->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $infant->fresh()->status);
    }

    public function test_the_action_shows_for_a_booked_passenger_once_check_in_has_closed(): void
    {
        // Flight past CheckinOpen.
        $booked = $this->passengerOn($this->flight);
        $done = $this->passengerOn($this->flight);
        $this->late($done);

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('Late check-in')
            ->assertSee('href="'.route('gate.passengers.late-checkin', $booked).'"', false)
            ->assertDontSee('href="'.route('gate.passengers.late-checkin', $done).'"', false);

        // Still CheckinOpen, window open: normal check-in applies.
        $open = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $early = $this->passengerOn($open);

        $this->get(route('gate.flights.show', $open))->assertOk()->assertDontSee('Late check-in');
        $this->late($early)->assertSessionHas('error', "Late check-in isn't needed: check-in is still open for this flight.");
        $this->assertSame(PassengerStatus::Booked, $early->fresh()->status);

        // Still CheckinOpen, window over: late check-in applies.
        $this->travelTo(Carbon::parse('2026-11-10 11:30'));
        $this->get(route('gate.flights.show', $open))->assertOk()->assertSee('Late check-in');
        $this->late($early)->assertSessionHas('success');
    }

    public function test_SR29_refused_once_boarding_is_closed(): void
    {
        $passenger = $this->passengerOn($this->flight);
        app(StatusService::class)->closeBoarding($this->flight, $this->gateAgent);

        $this->late($passenger)->assertSessionHas('error', 'Boarding is closed for this flight.');
        $this->assertNotCheckedIn($passenger);

        $this->get(route('gate.flights.show', $this->flight))->assertOk()->assertDontSee('Late check-in');
        $this->get(route('gate.passengers.late-checkin', $passenger))
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('error', 'Late check-in is not available for this passenger.');
    }

    public function test_gate_user_can_open_the_printable_pass_after_a_late_check_in(): void
    {
        $passenger = $this->passengerOn($this->flight, null, ['first_name' => 'Rami', 'last_name' => 'Haddad']);
        $url = route('gate.flights.boarding-pass', [$this->flight, $passenger]);

        // Success lands on the manifest with a link to the pass.
        $this->followingRedirects()
            ->post(route('gate.passengers.late-checkin.store', $passenger), [
                'reason' => self::REASON, 'manager_email' => 'boss@arcs.test', 'password' => 'password',
            ])
            ->assertOk()
            ->assertSee('Rami Haddad checked in late.')
            ->assertSee('href="'.$url.'"', false);

        $pass = $passenger->fresh()->activeBoardingPass;

        $this->get($url)
            ->assertOk()
            ->assertSee('HADDAD / RAMI')
            ->assertSee($passenger->reservation->pnr)
            ->assertSee('<svg', false)
            ->assertSee('data-barcode="'.$pass->barcode.'"', false);

        // Read only: opening it twice issues nothing.
        $this->get($url)->assertOk();
        $this->assertSame(1, $passenger->boardingPasses()->count());
    }

    public function test_the_gate_pass_page_also_works_for_a_boarded_passenger(): void
    {
        $passenger = $this->passengerOn($this->flight);
        $this->late($passenger)->assertSessionHas('success');
        $this->board($passenger);

        $this->get(route('gate.flights.boarding-pass', [$this->flight, $passenger]))->assertOk();
    }

    public function test_the_gate_pass_page_is_only_for_checked_in_or_boarded_passengers_of_that_flight(): void
    {
        $booked = $this->passengerOn($this->flight);
        $this->get(route('gate.flights.boarding-pass', [$this->flight, $booked]))->assertNotFound();

        $passenger = $this->passengerOn($this->flight);
        $this->late($passenger)->assertSessionHas('success');

        $other = $this->flightWithStatus(FlightStatus::Boarding);
        $this->get(route('gate.flights.boarding-pass', [$other, $passenger]))->assertNotFound();

        // A no-show keeps their pass row, but it is no longer shown at the gate.
        app(StatusService::class)->closeBoarding($this->flight, $this->gateAgent);
        $this->get(route('gate.flights.boarding-pass', [$this->flight, $passenger]))->assertNotFound();
    }

    public function test_the_service_refuses_a_non_manager_approver(): void
    {
        $passenger = $this->passengerOn($this->flight);

        try {
            app(StatusService::class)->lateCheckIn($passenger, $this->gateAgent, $this->checkinAgent, self::REASON);
            $this->fail('Expected the approval to be refused.');
        } catch (InvalidStatusChange $exception) {
            $this->assertSame('A late check-in must be approved by a Manager.', $exception->getMessage());
        }

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_checkin_and_reservation_users_get_403(): void
    {
        $passenger = $this->passengerOn($this->flight);

        foreach (['checkin', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('gate.passengers.late-checkin', $passenger))->assertForbidden();
            $this->post(route('gate.passengers.late-checkin.store', $passenger), [
                'reason' => self::REASON, 'manager_email' => 'boss@arcs.test', 'password' => 'password',
            ])->assertForbidden();
            $this->get(route('gate.flights.boarding-pass', [$this->flight, $passenger]))->assertForbidden();
        }

        $this->assertNotCheckedIn($passenger);
    }
}
