<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class C11CollectExcessTest extends TestCase
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
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME501']);
        $this->seedBookingPrices();
        $this->bags = app(BagService::class);
    }

    private function passenger(array $attributes = []): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create($attributes + [
            'cabin_class' => CabinClass::Economy,
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        return $passenger->fresh();
    }

    private function withFiveKgExcess(Passenger $passenger): int
    {
        // Economy 2×23: 28+23 = 5 kg excess → $50.
        $this->bags->add($passenger, 28, BagType::Standard, $this->agent);
        $this->bags->add($passenger, 23, BagType::Standard, $this->agent);

        return $this->bags->excess($passenger->fresh())->quote->totalCents;
    }

    private function pay(Passenger $passenger, array $card = []): TestResponse
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.excess.pay', $passenger), array_merge([
                'card_number' => '4242 4242 4242 4242',
                'card_expiry' => '12/30',
                'card_cvc' => '123',
                'card_name' => 'Rami Haddad',
            ], $card));
    }

    public function test_SR60_five_kg_excess_paid_with_4242_unblocks_confirm(): void
    {
        $passenger = $this->passenger();
        $due = $this->withFiveKgExcess($passenger);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Collect payment', false)
            ->assertSee(Money::format($due), false);

        $this->pay($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success');

        $payment = Payment::query()->where('passenger_id', $passenger->id)->sole();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(PaymentKind::ExcessBaggage, $payment->kind);
        $this->assertSame($due, $payment->amount_cents);
        $this->assertSame('4242', $payment->card_last4);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('••••4242', false)
            ->assertSee($payment->gateway_reference, false);

        $this->post(route('checkin.passengers.confirm', $passenger))
            ->assertSessionHas('success');
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }

    public function test_SR60_declined_card_keeps_confirm_blocked(): void
    {
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);

        $this->pay($passenger, ['card_number' => '4000 0000 0000 0002'])
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Card declined');

        $this->assertSame(1, Payment::where('status', PaymentStatus::Failed)->count());
        $this->assertSame(0, Payment::where('status', PaymentStatus::Succeeded)->count());

        $this->post(route('checkin.passengers.confirm', $passenger))
            ->assertSessionHas('error', 'Excess baggage not paid');
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_SR60_no_double_payment_when_already_paid(): void
    {
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);
        $this->pay($passenger)->assertSessionHas('success');

        $this->pay($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error');

        $this->assertSame(1, Payment::where('status', PaymentStatus::Succeeded)->count());
    }

    public function test_SR60_new_bag_after_payment_only_the_difference_is_due(): void
    {
        $passenger = $this->passenger();
        $firstDue = $this->withFiveKgExcess($passenger);
        $this->pay($passenger)->assertSessionHas('success');

        // Third bag 23 kg: 1 extra bag + 5 kg still over the expanded pool → $50 + $50.
        $this->bags->add($passenger, 23, BagType::Standard, $this->agent);
        $excess = $this->bags->excess($passenger->fresh());
        $this->assertSame(1, $excess->extraBags);
        $this->assertSame(5.0, $excess->excessKg);
        $this->assertSame(10000, $excess->quote->totalCents); // ExtraBag $50 + ExcessKg $50

        $due = $this->bags->excessDueCents($passenger->fresh());
        $this->assertSame(10000 - $firstDue, $due);
        $this->assertSame(5000, $due);

        $this->pay($passenger)->assertSessionHas('success');
        $this->assertSame(2, Payment::where('status', PaymentStatus::Succeeded)->count());
        $this->assertSame(5000, Payment::where('status', PaymentStatus::Succeeded)->latest('id')->value('amount_cents'));
        $this->assertSame(0, $this->bags->excessDueCents($passenger->fresh()));

        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');
    }

    public function test_SR60_full_card_number_appears_nowhere(): void
    {
        Log::spy();
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);

        $this->pay($passenger)->assertSessionHas('success');

        $this->assertDatabaseMissing('payments', ['card_last4' => '4242424242424242']);
        $this->assertSame(0, Payment::where('card_last4', 'like', '%42424242%')->count());

        $page = $this->get(route('checkin.passengers.show', $passenger))->assertOk();
        $page->assertDontSee('4242 4242 4242 4242', false);
        $page->assertDontSee('4242424242424242', false);
        $this->assertStringNotContainsString('4242424242424242', json_encode(session()->all()));

        foreach (Payment::all() as $payment) {
            $this->assertSame(4, strlen($payment->card_last4));
        }
    }

    public function test_SR60_group_screen_collects_per_passenger(): void
    {
        $reservation = Reservation::factory()->for($this->flight)->create(['pnr' => 'GRP111']);
        $one = Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy, 'first_name' => 'Rami', 'last_name' => 'One',
        ]);
        $two = Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy, 'first_name' => 'Lara', 'last_name' => 'Two',
        ]);
        foreach ([$one, $two] as $passenger) {
            TravelDocument::factory()->for($passenger)->create();
            $this->seatPassenger($passenger);
            $this->withFiveKgExcess($passenger);
        }

        $this->get(route('checkin.reservations.group', $reservation))
            ->assertOk()
            ->assertSee(route('checkin.excess.pay', $one), false)
            ->assertSee(route('checkin.excess.pay', $two), false)
            ->assertSee('Collect payment', false);

        $this->pay($one)->assertSessionHas('success');
        $this->assertSame(1, Payment::where('passenger_id', $one->id)->where('status', PaymentStatus::Succeeded)->count());
        $this->assertSame(0, Payment::where('passenger_id', $two->id)->where('status', PaymentStatus::Succeeded)->count());
    }

    public function test_SR60_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('checkin.excess.pay', $passenger), [
                'card_number' => '4242 4242 4242 4242',
                'card_expiry' => '12/30',
                'card_cvc' => '123',
            ])->assertForbidden();
        }

        $this->assertSame(0, Payment::count());
    }
}
