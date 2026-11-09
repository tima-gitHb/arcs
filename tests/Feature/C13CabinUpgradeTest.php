<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\AllowanceService;
use App\Services\BoardingPassService;
use App\Services\FareService;
use App\Services\StatusService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C13CabinUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME601']);
        $this->seedBookingPrices();
    }

    private function economyAdult(): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => PassengerType::Adult,
            'cabin_class' => CabinClass::Economy,
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $seat = Seat::factory()->create([
            'aircraft_id' => $this->flight->aircraft_id,
            'seat_no' => '14C',
            'cabin_class' => CabinClass::Economy,
        ]);
        SeatAssignment::create([
            'flight_id' => $this->flight->id,
            'seat_id' => $seat->id,
            'passenger_id' => $passenger->id,
        ]);

        return $passenger->fresh(['seatAssignment.seat', 'reservation']);
    }

    private function businessSeat(string $seatNo = '1A'): Seat
    {
        return Seat::factory()->create([
            'aircraft_id' => $this->flight->aircraft_id,
            'seat_no' => $seatNo,
            'cabin_class' => CabinClass::Business,
        ]);
    }

    private function upgradeDiff(Passenger $passenger, CabinClass $to): int
    {
        $fares = app(FareService::class);
        $new = $fares->quote([['cabin_class' => $to, 'type' => $passenger->type]])->totalCents;
        $old = $fares->quote([['cabin_class' => $passenger->cabin_class, 'type' => $passenger->type]])->totalCents;

        return max(0, $new - $old);
    }

    private function postUpgrade(Passenger $passenger, Seat $seat, array $card = [])
    {
        return $this->from(route('checkin.passengers.upgrade', $passenger))
            ->post(route('checkin.passengers.upgrade.store', $passenger), array_merge([
                'cabin_class' => CabinClass::Business->value,
                'seat_id' => $seat->id,
                'card_number' => '4242 4242 4242 4242',
                'card_expiry' => '12/30',
                'card_cvc' => '123',
                'card_name' => 'Rami Haddad',
            ], $card));
    }

    public function test_SR_upgrade_economy_adult_to_business_pays_difference_and_moves_seat(): void
    {
        $passenger = $this->economyAdult();
        app(BoardingPassService::class)->completeCheckIn($passenger, $this->agent);
        $oldPass = $passenger->fresh()->activeBoardingPass;
        $this->assertNotNull($oldPass);
        $oldSeatId = $passenger->seatAssignment->seat_id;
        $business = $this->businessSeat('2A');
        $due = $this->upgradeDiff($passenger, CabinClass::Business);

        $this->get(route('checkin.passengers.upgrade', $passenger))
            ->assertOk()
            ->assertSee('Upgrade')
            ->assertSee(Money::format($due), false)
            ->assertSee('id="seat-map"', false);

        $this->postUpgrade($passenger, $business)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success');

        $passenger->refresh()->load(['seatAssignment.seat', 'activeBoardingPass']);
        $this->assertSame(CabinClass::Business, $passenger->cabin_class);
        $this->assertSame('2A', $passenger->seatAssignment->seat->seat_no);
        $this->assertDatabaseMissing('seat_assignments', ['seat_id' => $oldSeatId]);

        $allowance = app(AllowanceService::class)->for($passenger);
        $this->assertSame(CabinClass::Business, $allowance->cabinClass);
        $this->assertSame(2, $allowance->pieces);
        $this->assertSame(32.0, $allowance->kgPerBag);

        $this->assertNotNull($passenger->activeBoardingPass);
        $this->assertNotSame($oldPass->id, $passenger->activeBoardingPass->id);
        $this->assertNotNull($oldPass->fresh()->voided_at);

        $this->get(route('checkin.boarding-pass.show', $passenger))
            ->assertOk()
            ->assertSee('>2A<', false);

        $payment = Payment::query()->where('passenger_id', $passenger->id)->where('kind', PaymentKind::Upgrade)->sole();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame($due, $payment->amount_cents);
    }

    public function test_SR_upgrade_declined_changes_nothing(): void
    {
        $passenger = $this->economyAdult();
        $business = $this->businessSeat();

        $this->postUpgrade($passenger, $business, ['card_number' => '4000 0000 0000 0002'])
            ->assertSessionHas('error', 'Card declined');

        $passenger->refresh();
        $this->assertSame(CabinClass::Economy, $passenger->cabin_class);
        $this->assertSame('14C', $passenger->seatAssignment->seat->seat_no);
        $this->assertSame(0, Payment::where('status', PaymentStatus::Succeeded)->count());
    }

    public function test_SR_upgrade_downgrade_is_refused(): void
    {
        $passenger = $this->economyAdult();
        $passenger->update(['cabin_class' => CabinClass::Business]);
        $passenger->seatAssignment->seat->update(['cabin_class' => CabinClass::Business, 'seat_no' => '1B']);

        $economy = Seat::factory()->create([
            'aircraft_id' => $this->flight->aircraft_id,
            'seat_no' => '20A',
            'cabin_class' => CabinClass::Economy,
        ]);

        $this->post(route('checkin.passengers.upgrade.store', $passenger), [
            'cabin_class' => CabinClass::Economy->value,
            'seat_id' => $economy->id,
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertSessionHas('error');

        $this->assertSame(CabinClass::Business, $passenger->fresh()->cabin_class);
    }

    public function test_SR_upgrade_refused_after_boarding_closed(): void
    {
        $passenger = $this->economyAdult();
        app(StatusService::class)->checkIn($passenger, $this->agent);
        $gate = User::factory()->create(['role' => Role::Gate]);
        app(StatusService::class)->setFlightStatus($this->flight, FlightStatus::Boarding, $gate);
        app(StatusService::class)->closeBoarding($this->flight, $gate);

        $this->get(route('checkin.passengers.upgrade', $passenger))->assertSessionHas('error');
        $this->postUpgrade($passenger, $this->businessSeat())
            ->assertSessionHas('error');
    }

    public function test_SR_upgrade_infant_follows_adult(): void
    {
        $adult = $this->economyAdult();
        $infant = Passenger::factory()->for($adult->reservation)->create([
            'type' => PassengerType::Infant,
            'accompanying_adult_id' => $adult->id,
            'cabin_class' => CabinClass::Economy,
            'first_name' => 'Noor',
            'last_name' => 'Haddad',
        ]);
        TravelDocument::factory()->for($infant)->create();

        $this->postUpgrade($adult, $this->businessSeat())->assertSessionHas('success');

        $this->assertSame(CabinClass::Business, $adult->fresh()->cabin_class);
        $this->assertSame(CabinClass::Business, $infant->fresh()->cabin_class);
    }

    public function test_SR_upgrade_taken_seat_in_new_class_is_refused(): void
    {
        $passenger = $this->economyAdult();
        $other = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create([
            'cabin_class' => CabinClass::Business,
        ]);
        TravelDocument::factory()->for($other)->create();
        $taken = $this->businessSeat('3A');
        SeatAssignment::create([
            'flight_id' => $this->flight->id,
            'seat_id' => $taken->id,
            'passenger_id' => $other->id,
        ]);

        $this->postUpgrade($passenger, $taken)->assertSessionHas('error', 'Seat taken');
        $this->assertSame(CabinClass::Economy, $passenger->fresh()->cabin_class);
        $this->assertSame(0, Payment::where('status', PaymentStatus::Succeeded)->count());
    }

    public function test_SR_upgrade_writes_an_audit_row(): void
    {
        $passenger = $this->economyAdult();
        $business = $this->businessSeat('4A');
        $due = $this->upgradeDiff($passenger, CabinClass::Business);

        $this->postUpgrade($passenger, $business)->assertSessionHas('success');

        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id,
            'field' => 'cabin_class',
            'old_value' => 'Economy',
            'new_value' => 'Business',
            'user_id' => $this->agent->id,
        ]);
        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'seat')
                ->where('old_value', '14C')
                ->where('new_value', '4A')
                ->exists()
        );
        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'payment')
                ->where('new_value', 'like', '%'.Money::format($due).'%')
                ->exists()
        );
    }

    public function test_SR_upgrade_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->economyAdult();
        $seat = $this->businessSeat();

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('checkin.passengers.upgrade', $passenger))->assertForbidden();
            $this->post(route('checkin.passengers.upgrade.store', $passenger), [
                'cabin_class' => CabinClass::Business->value,
                'seat_id' => $seat->id,
                'card_number' => '4242 4242 4242 4242',
                'card_expiry' => '12/30',
                'card_cvc' => '123',
            ])->assertForbidden();
        }
    }
}
