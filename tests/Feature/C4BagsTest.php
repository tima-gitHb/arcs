<?php

namespace Tests\Feature;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\BaggageAllowance;
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

class C4BagsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME201']);

        // The M4 allowances.
        BaggageAllowance::create(['cabin_class' => CabinClass::Economy, 'pieces' => 1, 'max_kg_per_bag' => 23.0]);
        BaggageAllowance::create(['cabin_class' => CabinClass::Business, 'pieces' => 2, 'max_kg_per_bag' => 32.0]);
    }

    private function passenger(CabinClass $cabin = CabinClass::Economy): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create();
        TravelDocument::factory()->for($passenger)->create();

        $seat = Seat::factory()->create(['aircraft_id' => $this->flight->aircraft_id, 'cabin_class' => $cabin]);
        SeatAssignment::create(['flight_id' => $this->flight->id, 'seat_id' => $seat->id, 'passenger_id' => $passenger->id]);

        return $passenger;
    }

    private function addBag(Passenger $passenger, float $kg, BagType $type = BagType::Standard)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.bags.store', $passenger), ['weight_kg' => $kg, 'type' => $type->value]);
    }

    /**
     * @return array<int, bool> over_allowance per bag, in the order they were added
     */
    private function flags(Passenger $passenger): array
    {
        return $passenger->bags()->orderBy('id')->pluck('over_allowance')->map(fn ($flag) => (bool) $flag)->all();
    }

    public function test_SR9_two_bags_get_unique_tags_linked_to_the_passenger(): void
    {
        $passenger = $this->passenger();

        $this->addBag($passenger, 18)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->addBag($passenger, 23)->assertSessionHas('success');

        $bags = $passenger->bags()->orderBy('id')->get();
        $this->assertCount(2, $bags);
        $this->assertSame(['18.0', '23.0'], $bags->pluck('weight_kg')->all());
        $this->assertNotSame($bags[0]->tag_no, $bags[1]->tag_no);
        foreach ($bags as $bag) {
            $this->assertMatchesRegularExpression('/^ME\d{6}$/', $bag->tag_no);
            $this->assertSame($passenger->id, $bag->passenger_id);
        }

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSeeInOrder([$bags[0]->tag_no, '18.0 kg', $bags[1]->tag_no, '23.0 kg']);
    }

    public function test_SR27_economy_second_bag_and_27_kg_bag_are_flagged(): void
    {
        $passenger = $this->passenger(CabinClass::Economy);

        $this->addBag($passenger, 20); // 1st piece, within 23 kg
        $this->addBag($passenger, 15); // 2nd piece: over the 1-piece allowance
        $this->assertSame([false, true], $this->flags($passenger));

        $heavy = $this->passenger(CabinClass::Economy);
        $this->addBag($heavy, 27);    // 1st piece but over 23 kg
        $this->assertSame([true], $this->flags($heavy));

        // Flagged, never charged: the bags are accepted and the screen shows the badge.
        $this->get(route('checkin.passengers.show', $heavy))
            ->assertOk()
            ->assertSee('Over allowance');
    }

    public function test_SR27_business_allowance_is_two_bags_of_32_kg(): void
    {
        $passenger = $this->passenger(CabinClass::Business);

        $this->addBag($passenger, 30);
        $this->addBag($passenger, 32);
        $this->addBag($passenger, 10);

        $this->assertSame([false, false, true], $this->flags($passenger));
    }

    public function test_SR43_wheelchair_and_stroller_do_not_count_toward_allowance(): void
    {
        $passenger = $this->passenger(CabinClass::Economy);

        $this->addBag($passenger, 20);
        $this->addBag($passenger, 15, BagType::Wheelchair);
        $this->addBag($passenger, 9, BagType::Stroller);
        $this->addBag($passenger, 10); // 2nd counted bag

        $this->assertSame([false, false, false, true], $this->flags($passenger));

        $wheelchair = $passenger->bags()->where('type', BagType::Wheelchair)->sole();
        $this->assertSame(BagHandling::GateDelivery, $wheelchair->handling);
        $this->assertSame(BagHandling::Hold, $passenger->bags()->where('type', BagType::Standard)->first()->handling);
    }

    public function test_SR27_moving_to_business_recalculates_the_flags(): void
    {
        $passenger = $this->passenger(CabinClass::Economy);
        $this->addBag($passenger, 20);
        $this->addBag($passenger, 30);
        $this->assertSame([false, true], $this->flags($passenger));

        $business = Seat::factory()->create([
            'aircraft_id' => $this->flight->aircraft_id, 'seat_no' => '2A', 'cabin_class' => CabinClass::Business,
        ]);
        $this->post(route('checkin.seats.assign', $passenger), ['seat_id' => $business->id])->assertSessionHas('success');

        $this->assertSame([false, false], $this->flags($passenger));
    }

    public function test_an_infant_uses_the_cabin_class_of_the_accompanying_adult(): void
    {
        $adult = $this->passenger(CabinClass::Business);
        $infant = Passenger::factory()->for($adult->reservation)->create([
            'type' => PassengerType::Infant,
            'accompanying_adult_id' => $adult->id,
        ]);

        $this->addBag($infant, 30); // within Business 32 kg
        $this->assertSame([false], $this->flags($infant));
    }

    public function test_adding_and_removing_a_bag_writes_audit_rows(): void
    {
        $passenger = $this->passenger();

        $this->addBag($passenger, 18);
        $bag = $passenger->bags()->sole();

        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id, 'field' => 'bag_added', 'old_value' => null,
            'new_value' => $bag->tag_no, 'user_id' => $this->agent->id,
        ]);

        $this->from(route('checkin.passengers.show', $passenger))
            ->delete(route('checkin.bags.destroy', $bag))
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bags', ['id' => $bag->id]);
        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id, 'field' => 'bag_removed', 'old_value' => $bag->tag_no,
            'new_value' => 'removed', 'user_id' => $this->agent->id,
        ]);
    }

    public function test_removing_a_bag_recalculates_the_flags(): void
    {
        $passenger = $this->passenger(CabinClass::Economy);
        $this->addBag($passenger, 20);
        $this->addBag($passenger, 15);
        $this->assertSame([false, true], $this->flags($passenger));

        $this->delete(route('checkin.bags.destroy', $passenger->bags()->orderBy('id')->first()));

        // The remaining bag is now the only piece.
        $this->assertSame([false], $this->flags($passenger));
    }

    public function test_a_bag_can_be_removed_only_before_check_in_is_confirmed(): void
    {
        $passenger = $this->passenger();
        $this->addBag($passenger, 18);
        $bag = $passenger->bags()->sole();

        app(StatusService::class)->checkIn($passenger, $this->agent);

        $this->delete(route('checkin.bags.destroy', $bag))
            ->assertSessionHas('error', 'A bag can only be removed before check-in is confirmed.');
        $this->assertDatabaseHas('bags', ['id' => $bag->id]);

        // Checked in: no remove button, but more bags can still be added.
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertDontSee(route('checkin.bags.destroy', $bag), false)
            ->assertSee(route('checkin.bags.store', $passenger), false);
        $this->addBag($passenger, 10)->assertSessionHas('success');
    }

    public function test_bags_cannot_be_added_for_cancelled_boarded_or_no_show_passengers(): void
    {
        $statuses = app(StatusService::class);
        $gate = User::factory()->create(['role' => Role::Gate]);

        $cancelled = $this->passenger();
        $statuses->cancel($cancelled, User::factory()->create(['role' => Role::Reservation]));

        $boarded = $this->passenger();
        $noShow = $this->passenger();
        $statuses->checkIn($boarded, $this->agent);
        $statuses->checkIn($noShow, $this->agent);
        $statuses->setFlightStatus($this->flight, FlightStatus::Boarding, $gate);
        $statuses->board($boarded, $gate);
        $statuses->closeBoarding($this->flight, $gate);

        foreach ([$cancelled, $boarded, $noShow] as $passenger) {
            $this->addBag($passenger, 18)
                ->assertSessionHas('error', 'Bags can only be added for a booked or checked-in passenger.');
            $this->assertSame(0, $passenger->bags()->count());

            $this->get(route('checkin.passengers.show', $passenger))
                ->assertDontSee(route('checkin.bags.store', $passenger), false);
        }
    }

    public function test_bag_form_is_validated(): void
    {
        $passenger = $this->passenger();

        $this->post(route('checkin.bags.store', $passenger), ['weight_kg' => 'heavy', 'type' => 'Suitcase'])
            ->assertSessionHasErrorsIn('addBag', ['weight_kg', 'type']);
        $this->post(route('checkin.bags.store', $passenger), ['weight_kg' => 0, 'type' => 'Standard'])
            ->assertSessionHasErrorsIn('addBag', ['weight_kg']);

        $this->assertSame(0, $passenger->bags()->count());
    }

    public function test_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->passenger();
        $this->addBag($passenger, 18);
        $bag = $passenger->bags()->sole();

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('checkin.bags.store', $passenger), ['weight_kg' => 10, 'type' => 'Standard'])->assertForbidden();
            $this->delete(route('checkin.bags.destroy', $bag))->assertForbidden();
        }

        $this->assertSame(1, Bag::count());
        $this->assertSame(1, AuditEntry::where('field', 'bag_added')->count());
    }
}
