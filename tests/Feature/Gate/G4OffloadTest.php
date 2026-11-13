<?php

namespace Tests\Feature\Gate;

use App\Enums\FlightStatus;
use App\Exceptions\InvalidStatusChange;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class G4OffloadTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    private Flight $flight;

    private Passenger $boarded;

    private Passenger $noShow;

    private Bag $first;

    private Bag $second;

    private Bag $boardedBag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Beirut')); // staff are at BEY (B13a)
        $this->makeAgents();
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME305']);

        $this->boarded = $this->passengerOn($this->flight);
        $this->noShow = $this->passengerOn($this->flight, null, ['first_name' => 'Ali', 'last_name' => 'Missing']);
        $this->checkIn($this->boarded, $this->noShow);

        $this->first = Bag::factory()->for($this->noShow)->create(['tag_no' => 'ME100001']);
        $this->second = Bag::factory()->for($this->noShow)->create(['tag_no' => 'ME100002']);
        $this->boardedBag = Bag::factory()->for($this->boarded)->create(['tag_no' => 'ME100009']);

        $this->startBoarding($this->flight);
        $this->board($this->boarded);
        app(StatusService::class)->closeBoarding($this->flight, $this->gateAgent);
    }

    private function confirm(Bag $bag)
    {
        return $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.bags.offload', $bag));
    }

    private function depart(): void
    {
        app(StatusService::class)->setFlightStatus($this->flight, FlightStatus::Departed, $this->gateAgent);
    }

    private function offloadSection(): string
    {
        $html = $this->get(route('gate.flights.show', $this->flight))->assertOk()->getContent();
        preg_match('/<section[^>]*id="offload"[\s\S]*?<\/section>/', $html, $match);

        return $match[0] ?? '';
    }

    public function test_SR28_departed_is_refused_until_both_bags_are_confirmed(): void
    {
        try {
            $this->depart();
            $this->fail('Expected the departure to be refused.');
        } catch (InvalidStatusChange $exception) {
            $this->assertSame('2 bags still to offload', $exception->getMessage());
        }
        $this->assertSame(FlightStatus::Closed, $this->flight->fresh()->status);

        $this->confirm($this->first)->assertSessionHas('success');

        try {
            $this->depart();
            $this->fail('Expected the departure to be refused.');
        } catch (InvalidStatusChange $exception) {
            $this->assertSame('1 bag still to offload', $exception->getMessage());
        }

        $this->confirm($this->second)->assertSessionHas('success');

        $this->depart();
        $this->assertSame(FlightStatus::Departed, $this->flight->fresh()->status);
    }

    public function test_SR28_the_manager_flights_page_obeys_the_rule_too(): void
    {
        $this->actingAsRole('manager');

        $this->from(route('manager.flights'))
            ->patch(route('manager.flights.status', $this->flight), ['status' => 'Departed'])
            ->assertSessionHasErrors(['status' => '2 bags still to offload']);

        $this->assertSame(FlightStatus::Closed, $this->flight->fresh()->status);
    }

    public function test_SR28_a_closed_flight_without_no_show_bags_can_depart(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Closed);

        app(StatusService::class)->setFlightStatus($flight, FlightStatus::Departed, $this->gateAgent);

        $this->assertSame(FlightStatus::Departed, $flight->fresh()->status);
    }

    public function test_SR28_each_confirmation_records_who_and_when_and_is_audited(): void
    {
        $this->confirm($this->first)
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('success', 'Bag ME100001 offloaded.');

        $bag = $this->first->fresh();
        $this->assertSame($this->gateAgent->id, $bag->offloaded_by);
        $this->assertTrue($bag->offloaded_at->equalTo(now()));

        $entry = AuditEntry::where('field', 'bag_offloaded')->sole();
        $this->assertSame($this->noShow->id, $entry->passenger_id);
        $this->assertNull($entry->old_value);
        $this->assertSame('ME100001', $entry->new_value);
        $this->assertSame($this->gateAgent->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));
    }

    public function test_SR28_the_list_shows_done_bags_with_who_and_when_and_a_counter(): void
    {
        $section = $this->offloadSection();
        $this->assertStringContainsString('0 of 2 bags offloaded', $section);
        $this->assertStringContainsString(route('gate.bags.offload', $this->first), $section);
        $this->assertStringContainsString(route('gate.bags.offload', $this->second), $section);
        $this->assertStringContainsString('Confirm offloaded', $section);

        $this->travelTo(now()->addMinutes(5));
        $this->confirm($this->first);

        $section = $this->offloadSection();
        $this->assertStringContainsString('1 of 2 bags offloaded', $section);
        $this->assertStringContainsString('ME100001', $section); // stays on the list, as done
        $this->assertStringContainsString('Offloaded by Joe Saliba at 10:05', $section);
        $this->assertStringNotContainsString(route('gate.bags.offload', $this->first), $section);
        $this->assertStringContainsString(route('gate.bags.offload', $this->second), $section);
        $this->assertStringNotContainsString('ME100009', $section);
    }

    public function test_SR28_confirming_twice_is_refused(): void
    {
        $this->confirm($this->first)->assertSessionHas('success');
        $firstTime = $this->first->fresh()->offloaded_at;

        $this->travelTo(now()->addMinutes(5));
        $this->confirm($this->first)->assertSessionHas('error', 'Bag ME100001 is already offloaded.');

        $this->assertTrue($this->first->fresh()->offloaded_at->equalTo($firstTime));
        $this->assertSame(1, AuditEntry::where('field', 'bag_offloaded')->count());
    }

    public function test_SR28_a_bag_of_a_boarded_passenger_cannot_be_offloaded(): void
    {
        $this->confirm($this->boardedBag)
            ->assertSessionHas('error', 'Only the bags of a no-show passenger can be offloaded.');

        $this->assertNull($this->boardedBag->fresh()->offloaded_at);
        $this->assertSame(0, AuditEntry::where('field', 'bag_offloaded')->count());
    }

    public function test_checkin_and_reservation_users_get_403(): void
    {
        foreach (['checkin', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('gate.bags.offload', $this->first))->assertForbidden();
        }

        $this->assertNull($this->first->fresh()->offloaded_at);
    }
}
