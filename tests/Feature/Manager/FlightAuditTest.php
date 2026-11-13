<?php

namespace Tests\Feature\Manager;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M10 — Audit flight status and gate changes (SR-55).
 * A flight row has flight_id set and passenger_id null. Status values are stored as the
 * enum value (CheckinOpen) and shown on the audit page with the label (Check-in open).
 */
class FlightAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00:00', 'Asia/Beirut')); // staff are at BEY (B13a)
    }

    /**
     * A Booked passenger with a passport and a seat, checked in through StatusService.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function checkedInPassenger(Flight $flight, array $attributes = []): Passenger
    {
        $passenger = Passenger::factory()
            ->for(Reservation::factory()->for($flight))
            ->create($attributes);

        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);
        app(StatusService::class)->checkIn($passenger, User::factory()->create(['role' => Role::Checkin]));

        return $passenger;
    }

    public function test_SR55_starting_boarding_records_the_gate_user_and_the_time(): void
    {
        $joe = $this->actingAsRole(Role::Gate);
        $joe->update(['name' => 'Joe Saliba']);
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME305']);

        $this->post(route('gate.flights.start-boarding', $flight))
            ->assertRedirect(route('gate.flights.show', $flight));

        $this->assertSame(FlightStatus::Boarding, $flight->fresh()->status);

        $entry = AuditEntry::where('field', 'flight_status')->where('new_value', 'Boarding')->sole();
        $this->assertSame($flight->id, $entry->flight_id);
        $this->assertNull($entry->passenger_id);
        $this->assertSame('CheckinOpen', $entry->old_value);
        $this->assertSame($joe->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));

        $this->actingAsRole(Role::Manager);
        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('Flight ME305')
            ->assertSee('Check-in open → Boarding')
            ->assertSee('Joe Saliba')
            ->assertSee('10 Nov 2026 10:00');
    }

    public function test_SR55_close_boarding_records_the_flight_row_before_the_no_show_rows(): void
    {
        $joe = User::factory()->create(['role' => Role::Gate, 'name' => 'Joe Saliba']);
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME110']);
        $missing = $this->checkedInPassenger($flight, ['first_name' => 'Nour', 'last_name' => 'Aoun']);

        app(StatusService::class)->setFlightStatus($flight, FlightStatus::Boarding, $joe);
        app(StatusService::class)->closeBoarding($flight, $joe);

        $this->assertSame(FlightStatus::Closed, $flight->fresh()->status);
        $this->assertSame(PassengerStatus::NoShow, $missing->fresh()->status);

        $flightRow = AuditEntry::where('field', 'flight_status')->where('new_value', 'Closed')->sole();
        $this->assertSame($flight->id, $flightRow->flight_id);
        $this->assertNull($flightRow->passenger_id);
        $this->assertSame('Boarding', $flightRow->old_value);
        $this->assertSame($joe->id, $flightRow->user_id);
        $this->assertTrue($flightRow->changed_at->equalTo(now()));

        $noShow = AuditEntry::where('passenger_id', $missing->id)->where('new_value', 'NoShow')->sole();
        $this->assertSame('status', $noShow->field);
        $this->assertSame('CheckedIn', $noShow->old_value);
        $this->assertSame($joe->id, $noShow->user_id);
        $this->assertTrue($flightRow->id < $noShow->id);
    }

    public function test_SR55_a_refused_status_move_writes_nothing(): void
    {
        $flight = Flight::factory()->create();
        $manager = User::factory()->create(['role' => Role::Manager]);

        try {
            app(StatusService::class)->setFlightStatus($flight, FlightStatus::Departed, $manager);
            $this->fail('A flight cannot go from Scheduled to Departed.');
        } catch (InvalidStatusChange) {
        }

        $this->assertSame(FlightStatus::Scheduled, $flight->fresh()->status);
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_SR55_changing_the_gate_records_old_and_new(): void
    {
        $manager = $this->actingAsRole(Role::Manager);
        $flight = Flight::factory()->create(['flight_no' => 'ME201', 'gate' => 'A3']);

        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'B7'])
            ->assertSessionHasNoErrors();

        $this->assertSame('B7', $flight->fresh()->gate);

        $entry = AuditEntry::where('field', 'gate')->sole();
        $this->assertSame($flight->id, $entry->flight_id);
        $this->assertNull($entry->passenger_id);
        $this->assertSame('A3', $entry->old_value);
        $this->assertSame('B7', $entry->new_value);
        $this->assertSame($manager->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));

        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('Flight ME201')
            ->assertSee('A3 → B7');
    }

    public function test_SR55_clearing_the_gate_is_recorded_and_shown_as_a_dash(): void
    {
        $manager = $this->actingAsRole(Role::Manager);
        $flight = Flight::factory()->create(['flight_no' => 'ME201', 'gate' => 'A3']);

        $this->patch(route('manager.flights.gate', $flight), ['gate' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($flight->fresh()->gate);

        $entry = AuditEntry::where('field', 'gate')->sole();
        $this->assertSame('A3', $entry->old_value);
        $this->assertSame('', $entry->new_value);
        $this->assertSame($manager->id, $entry->user_id);

        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('A3 → —', false);
    }

    public function test_SR55_an_unchanged_gate_writes_nothing(): void
    {
        $this->actingAsRole(Role::Manager);
        $flight = Flight::factory()->create(['gate' => 'A3']);

        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'A3'])
            ->assertSessionHasNoErrors();

        $this->assertSame('A3', $flight->fresh()->gate);
        $this->assertSame(0, AuditEntry::where('field', 'gate')->count());
    }

    public function test_SR55_the_flight_filter_shows_the_flight_row_and_the_passenger_row(): void
    {
        $this->actingAsRole(Role::Manager);
        $flightA = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME901']);
        $flightB = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME902']);
        $onA = $this->checkedInPassenger($flightA, ['first_name' => 'Rami', 'last_name' => 'Hadad']);
        $onB = $this->checkedInPassenger($flightB, ['first_name' => 'Lina', 'last_name' => 'Karam']);

        $entries = $this->get(route('manager.audit', ['flight_id' => $flightA->id]))
            ->assertOk()
            ->assertSee('Flight ME901')
            ->assertSee('Rami Hadad')
            ->assertDontSee('Flight ME902')
            ->assertDontSee('Lina Karam')
            ->viewData('entries');

        $this->assertTrue($entries->contains(fn (AuditEntry $entry) => $entry->passenger_id === null
            && $entry->flight_id === $flightA->id
            && $entry->field === 'flight_status'));
        $this->assertTrue($entries->contains(fn (AuditEntry $entry) => $entry->passenger_id === $onA->id));
        $this->assertFalse($entries->contains(fn (AuditEntry $entry) => $entry->passenger_id === $onB->id
            || ($entry->passenger_id === null && $entry->flight_id === $flightB->id)));
    }

    public function test_SR55_a_non_manager_cannot_open_the_audit_log(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get(route('manager.audit'))->assertForbidden();
        }
    }
}
