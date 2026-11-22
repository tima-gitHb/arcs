<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\BaggageAllowance;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C10WeightPoolingTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    private BagService $bags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => $this->flightNo('302')]);
        $this->seedBookingPrices();
        $this->bags = app(BagService::class);
    }

    private function passenger(array $attributes = []): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create($attributes + [
            'cabin_class' => CabinClass::Economy,
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        return $passenger->fresh();
    }

    private function addViaService(Passenger $passenger, float $kg, BagType $type = BagType::Standard)
    {
        return $this->bags->add($passenger, $kg, $type, $this->agent);
    }

    public function test_SR59_two_bags_20_and_26_kg_have_no_excess(): void
    {
        $passenger = $this->passenger();

        $this->addViaService($passenger, 20);
        $this->addViaService($passenger, 26);

        $excess = $this->bags->excess($passenger->fresh());

        $this->assertSame(0, $excess->extraBags);
        $this->assertSame(0.0, $excess->excessKg);
        $this->assertFalse($excess->hasExcess());

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertDontSee('Excess baggage');
    }

    public function test_SR59_two_bags_28_and_23_kg_have_5_kg_excess(): void
    {
        $passenger = $this->passenger();

        $this->addViaService($passenger, 28);
        $this->addViaService($passenger, 23);

        $excess = $this->bags->excess($passenger->fresh());

        $this->assertSame(0, $excess->extraBags);
        $this->assertSame(5.0, $excess->excessKg);
        $this->assertTrue($excess->hasExcess());

        $quote = $excess->quote;
        $this->assertSame(5000, $quote->totalCents); // 5 × $10

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('5 kg excess', false)
            ->assertSee(Money::format(5000), false);
    }

    public function test_SR59_three_bags_mean_one_extra_bag(): void
    {
        $passenger = $this->passenger();

        $this->addViaService($passenger, 10);
        $this->addViaService($passenger, 10);
        $this->addViaService($passenger, 10);

        $excess = $this->bags->excess($passenger->fresh());

        $this->assertSame(1, $excess->extraBags);
        $this->assertSame(0.0, $excess->excessKg);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('1 extra bag', false)
            ->assertSee(Money::format(5000), false); // ExtraBag fee $50
    }

    public function test_SR59_extra_bag_does_not_double_charge_weight(): void
    {
        // Economy 1×23: allowed kg expands with each extra bag.
        BaggageAllowance::query()->updateOrCreate(
            ['cabin_class' => CabinClass::Economy],
            ['pieces' => 1, 'max_kg_per_bag' => 23.0],
        );

        $light = $this->passenger();
        $this->addViaService($light, 20);
        $this->addViaService($light, 15);
        $excess = $this->bags->excess($light->fresh());
        $this->assertSame(1, $excess->extraBags);
        $this->assertSame(0.0, $excess->excessKg);

        $heavy = $this->passenger();
        $this->addViaService($heavy, 20);
        $this->addViaService($heavy, 30);
        $excess = $this->bags->excess($heavy->fresh());
        $this->assertSame(1, $excess->extraBags);
        $this->assertSame(4.0, $excess->excessKg);
        $this->assertSame(9000, $excess->quote->totalCents); // ExtraBag $50 + 4×$10
    }

    public function test_SR59_missing_fee_shows_price_not_set_and_still_blocks_confirm(): void
    {
        Fee::query()->whereIn('code', [FeeCode::ExtraBag, FeeCode::ExcessKg])->delete();

        $passenger = $this->passenger();
        $this->addViaService($passenger, 10);
        $this->addViaService($passenger, 10);
        $this->addViaService($passenger, 10);

        $excess = $this->bags->excess($passenger->fresh());
        $this->assertTrue($excess->hasExcess());
        $this->assertFalse($excess->priceIsSet());

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Price not set', false);

        $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.passengers.confirm', $passenger))
            ->assertSessionHas('error', 'Excess baggage not paid');

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_SR59_a_bag_of_33_kg_is_refused(): void
    {
        $passenger = $this->passenger();

        $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.bags.store', $passenger), ['weight_kg' => 33, 'type' => BagType::Standard->value])
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Bag over 32 kg must be repacked');

        $this->assertSame(0, $passenger->bags()->count());
    }

    public function test_SR59_wheelchair_and_stroller_are_ignored_for_pooling(): void
    {
        $passenger = $this->passenger();

        $this->addViaService($passenger, 20);
        $this->addViaService($passenger, 15, BagType::Wheelchair);
        $this->addViaService($passenger, 9, BagType::Stroller);
        $this->addViaService($passenger, 26);

        $excess = $this->bags->excess($passenger->fresh());

        $this->assertSame(0, $excess->extraBags);
        $this->assertSame(0.0, $excess->excessKg);
        $this->assertFalse($excess->hasExcess());
    }

    public function test_SR59_extras_bought_at_booking_raise_the_allowance(): void
    {
        $passenger = $this->passenger(['extra_bags' => 1, 'extra_kg' => 5]);

        // Economy 2 + 1 extra = 3 pieces; total kg = 3×23 + 5 = 74.
        $this->addViaService($passenger, 23);
        $this->addViaService($passenger, 23);
        $this->addViaService($passenger, 23);

        $excess = $this->bags->excess($passenger->fresh());

        $this->assertSame(0, $excess->extraBags);
        $this->assertSame(0.0, $excess->excessKg);

        $this->addViaService($passenger, 10);
        $excess = $this->bags->excess($passenger->fresh());
        $this->assertSame(1, $excess->extraBags);
    }

    public function test_SR59_confirm_check_in_is_refused_while_excess_is_unpaid(): void
    {
        $passenger = $this->passenger();
        $this->addViaService($passenger, 28);
        $this->addViaService($passenger, 23);

        $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.passengers.confirm', $passenger))
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Excess baggage not paid');

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_SR59_late_check_in_is_refused_while_excess_is_unpaid(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $flight = $this->flightWithStatus(FlightStatus::Boarding, ['flight_no' => $this->flightNo('303')]);
        $manager = User::factory()->create(['role' => Role::Manager, 'email' => 'boss@arcs.test']);
        $this->actingAsRole('gate');

        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create([
            'cabin_class' => CabinClass::Economy,
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->bags->add($passenger, 28, BagType::Standard, $this->agent);
        $this->bags->add($passenger, 23, BagType::Standard, $this->agent);

        $this->from(route('gate.passengers.late-checkin', $passenger))
            ->post(route('gate.passengers.late-checkin.store', $passenger), [
                'reason' => 'Passenger at the gate after check-in closed',
                'manager_email' => $manager->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('gate.passengers.late-checkin', $passenger))
            ->assertSessionHas('error', 'Excess baggage not paid');

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertDatabaseCount('gate_overrides', 0);
    }

    public function test_SR59_confirm_succeeds_when_excess_has_a_succeeded_payment(): void
    {
        $passenger = $this->passenger();
        $this->addViaService($passenger, 28);
        $this->addViaService($passenger, 23);

        Payment::create([
            'reservation_id' => $passenger->reservation_id,
            'passenger_id' => $passenger->id,
            'kind' => PaymentKind::ExcessBaggage,
            'amount_cents' => 5000,
            'currency' => 'USD',
            'method' => 'card',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'gateway_reference' => 'TEST-EXCESS-1',
            'status' => PaymentStatus::Succeeded,
            'taken_by' => $this->agent->id,
            'paid_at' => now(),
        ]);

        $this->post(route('checkin.passengers.confirm', $passenger))
            ->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }
}
