<?php

namespace Tests\Feature\Manager;

use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\CorrectionService;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M5 — Audit log (SR-26, SR-45). The page is read only: every row in these tests is
 * written by StatusService or CorrectionService, never inserted or set directly.
 *
 * The view receives: $entries (paginator of AuditEntry), $flights, $staff.
 * Filters are GET parameters: flight_id, passenger, user_id, date.
 */
class AuditTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $checkinAgent;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus() opens the check-in window around now(): 09:00 to 11:15.
        $this->travelTo('2026-11-10 10:00:00');

        $this->manager = $this->actingAsRole(Role::Manager);
        $this->checkinAgent = User::factory()->create(['role' => Role::Checkin]);
    }

    /**
     * A passenger on this flight with a valid passport, checked in through StatusService.
     *
     * @param  array<string, mixed>  $passenger  overrides for the passenger
     * @param  array<string, mixed>  $reservation  overrides for the reservation (e.g. pnr)
     */
    private function checkedInPassenger(Flight $flight, array $passenger = [], array $reservation = [], ?User $by = null): Passenger
    {
        $model = Passenger::factory()
            ->for(Reservation::factory()->for($flight)->state($reservation))
            ->create($passenger);

        TravelDocument::factory()->for($model)->create();
        $this->seatPassenger($model); // check-in needs a seat (C5)

        app(StatusService::class)->checkIn($model, $by ?? $this->checkinAgent);

        return $model;
    }

    /**
     * @return list<int> ids of the audit entries the page received
     */
    private function entryIds(TestResponse $response): array
    {
        return $response->viewData('entries')->pluck('id')->all();
    }

    public function test_SR26_check_in_and_boarding_show_two_entries_with_staff_and_time(): void
    {
        $gateAgent = User::factory()->create(['role' => Role::Gate, 'name' => 'Gate Gina']);
        $this->checkinAgent->update(['name' => 'Checkin Carl']);

        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = $this->checkedInPassenger($flight, ['first_name' => 'Zaid', 'last_name' => 'Qortbawi']);

        app(StatusService::class)->setFlightStatus($flight, FlightStatus::Boarding, $this->manager);
        $this->travelTo('2026-11-10 10:30:00');
        app(StatusService::class)->board($passenger, $gateAgent);

        $response = $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('Zaid Qortbawi')
            ->assertSee('Booked → CheckedIn')
            ->assertSee('CheckedIn → Boarded')
            ->assertSee('Checkin Carl')
            ->assertSee('Gate Gina')
            ->assertSee('10 Nov 2026 10:00')
            ->assertSee('10 Nov 2026 10:30');

        $entries = $response->viewData('entries');
        $this->assertCount(2, $entries->whereNotNull('passenger_id'));

        // Newest first: boarding (gate), then check-in (check-in). Flight rows are not part of this.
        $passengers = $entries->whereNotNull('passenger_id');
        $this->assertSame(['Boarded', 'CheckedIn'], $passengers->pluck('new_value')->all());
        $this->assertSame([$gateAgent->id, $this->checkinAgent->id], $passengers->pluck('user_id')->all());
    }

    public function test_correction_entries_appear_with_field_and_old_and_new_values(): void
    {
        $reservationAgent = User::factory()->create(['role' => Role::Reservation, 'name' => 'Reserv Rita']);
        $passenger = Passenger::factory()->create(['first_name' => 'Rami', 'last_name' => 'Hadad']);

        app(CorrectionService::class)->correctPassenger($passenger, ['last_name' => 'Haddad'], $reservationAgent);

        $response = $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('last_name')
            ->assertSee('Hadad → Haddad')
            ->assertSee('Reserv Rita');

        $entry = $response->viewData('entries')->sole();
        $this->assertSame('last_name', $entry->field);
        $this->assertSame('Hadad', $entry->old_value);
        $this->assertSame('Haddad', $entry->new_value);
    }

    public function test_filter_by_staff_shows_only_their_actions(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $otherAgent = User::factory()->create(['role' => Role::Checkin]);

        $mine = $this->checkedInPassenger($flight, by: $this->checkinAgent);
        $theirs = $this->checkedInPassenger($flight, by: $otherAgent);

        $response = $this->get(route('manager.audit', ['user_id' => $this->checkinAgent->id]))->assertOk();

        $entries = $response->viewData('entries');
        $this->assertCount(1, $entries);
        $this->assertSame($mine->id, $entries->first()->passenger_id);
        $this->assertNotContains($theirs->id, $entries->pluck('passenger_id')->all());
    }

    public function test_filter_by_flight(): void
    {
        $flightA = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME901']);
        $flightB = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME902']);

        $onA = $this->checkedInPassenger($flightA);
        $this->checkedInPassenger($flightB);

        $response = $this->get(route('manager.audit', ['flight_id' => $flightA->id]))
            ->assertOk()
            ->assertSee('ME901')   // the dropdown lists flights by number
            ->assertSee('ME902');

        $entries = $response->viewData('entries');
        $this->assertCount(1, $entries->whereNotNull('passenger_id'));
        $this->assertSame($onA->id, $entries->first()->passenger_id);
    }

    public function test_filter_by_passenger(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);

        $rami = $this->checkedInPassenger($flight, ['first_name' => 'Rami', 'last_name' => 'Hadad'], ['pnr' => 'KQ7M2X']);
        $lina = $this->checkedInPassenger($flight, ['first_name' => 'Lina', 'last_name' => 'Karam'], ['pnr' => 'BT4N9Z']);

        // by last name (partial, any case), by first name, and by PNR
        foreach (['hada', 'Rami', 'KQ7M2X'] as $search) {
            $entries = $this->get(route('manager.audit', ['passenger' => $search]))->assertOk()->viewData('entries');

            $this->assertSame([$rami->id], $entries->pluck('passenger_id')->all(), "search '{$search}'");
        }

        $entries = $this->get(route('manager.audit', ['passenger' => 'Karam']))->viewData('entries');
        $this->assertSame([$lina->id], $entries->pluck('passenger_id')->all());

        $entries = $this->get(route('manager.audit', ['passenger' => 'Nobody']))->viewData('entries');
        $this->assertCount(0, $entries);
    }

    public function test_filter_by_date(): void
    {
        $flightDay1 = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $day1 = $this->checkedInPassenger($flightDay1);

        // Next day, late evening: the date filter must use the day of changed_at, not the time.
        $this->travelTo('2026-11-11 23:30:00');
        $flightDay2 = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $day2 = $this->checkedInPassenger($flightDay2);

        $entries = $this->get(route('manager.audit', ['date' => '2026-11-10']))->assertOk()->viewData('entries');
        $this->assertSame([$day1->id], $entries->whereNotNull('passenger_id')->pluck('passenger_id')->all());

        $entries = $this->get(route('manager.audit', ['date' => '2026-11-11']))->viewData('entries');
        $this->assertSame([$day2->id], $entries->whereNotNull('passenger_id')->pluck('passenger_id')->all());

        $entries = $this->get(route('manager.audit', ['date' => '2026-11-12']))->viewData('entries');
        $this->assertCount(0, $entries);
    }

    public function test_entries_are_newest_first_and_paginated(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $otherAgent = User::factory()->create(['role' => Role::Checkin]);

        // 5 entries by the first agent at 10:10 (created first, so the lowest ids)...
        $this->travelTo('2026-11-10 10:10:00');
        for ($i = 0; $i < 5; $i++) {
            $this->checkedInPassenger($flight);
        }

        // ...then 25 at 10:00 (older time, higher ids), same second: ties must be ordered by id.
        $this->travelTo('2026-11-10 10:00:00');
        for ($i = 0; $i < 25; $i++) {
            $this->checkedInPassenger($flight);
        }

        // One more by someone else, to make the staff filter meaningful.
        $this->checkedInPassenger($flight, by: $otherAgent);

        // No filter: 31 entries, 25 on page 1, 6 on page 2.
        $page1 = $this->get(route('manager.audit'))->assertOk();
        $this->assertSame(31, AuditEntry::whereNotNull('passenger_id')->count());
        $this->assertCount(25, $page1->viewData('entries'));

        $expected = AuditEntry::query()->orderByDesc('changed_at')->orderByDesc('id')->pluck('id')->all();
        $this->assertSame(array_slice($expected, 0, 25), $this->entryIds($page1));

        // The five 10:10 entries come first (newest by time), the highest id of them on top.
        $late = AuditEntry::where('changed_at', '2026-11-10 10:10:00')->orderByDesc('id')->pluck('id')->all();
        $this->assertCount(5, $late);
        $this->assertSame($late, array_slice($this->entryIds($page1), 0, 5));

        $this->assertCount(6, $this->get(route('manager.audit', ['page' => 2]))->viewData('entries')->whereNotNull('passenger_id'));

        // With a filter: page 2 keeps it (the other agent's entry is never listed)
        // and the pagination links carry the filter.
        $filtered = $this->get(route('manager.audit', ['user_id' => $this->checkinAgent->id, 'page' => 2]))->assertOk();
        $entries = $filtered->viewData('entries');

        $this->assertSame(30, $entries->total());
        $this->assertCount(5, $entries);
        $this->assertSame([$this->checkinAgent->id], $entries->pluck('user_id')->unique()->values()->all());
        $this->assertStringContainsString('user_id='.$this->checkinAgent->id, $entries->previousPageUrl());
        $filtered->assertSee('user_id='.$this->checkinAgent->id, false);
    }

    public function test_non_manager_cannot_open_audit_log(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get(route('manager.audit'))->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('manager.audit'))->assertRedirect(route('login'));
    }
}
