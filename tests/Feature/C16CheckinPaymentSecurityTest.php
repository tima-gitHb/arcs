<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Services\JourneyService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * C16 — check-in payment security: excess + upgrade, single and group, card data never leaks.
 */
class C16CheckinPaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const FULL_CARD = '4242 4242 4242 4242';

    private const FULL_DIGITS = '4242424242424242';

    private const DECLINED = '4000 0000 0000 0002';

    private const DECLINED_DIGITS = '4000000000000002';

    private const CVC = '987';

    private User $agent;

    private Flight $flight;

    private BagService $bags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('501'),
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        $this->seedBookingPrices();
        $this->bags = app(BagService::class);
    }

    private function passenger(array $attributes = []): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flight))->create($attributes + [
            'cabin_class' => CabinClass::Economy,
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => PassengerType::Adult,
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger, '14C');

        return $passenger->fresh();
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('264'),
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('216'),
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:15', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 10:45', 'UTC'),
        ]);

        return [$first, $second];
    }

    private function connectingPassenger(): Passenger
    {
        [$first, $second] = $this->connection();
        $reservation = Reservation::factory()->create(['flight_id' => $first->id, 'pnr' => 'SECURE']);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));

        $passenger = Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy,
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => PassengerType::Adult,
        ]);
        TravelDocument::factory()->for($passenger)->create();

        foreach ($passenger->coupons as $coupon) {
            $this->seatCoupon($coupon);
        }

        return $passenger->fresh();
    }

    private function seatCoupon(Coupon $coupon, CabinClass $cabin = CabinClass::Economy): void
    {
        $row = 1;
        while (Seat::query()
            ->where('aircraft_id', $coupon->flight->aircraft_id)
            ->where('seat_no', $row.'A')
            ->exists()) {
            $row++;
        }

        $seat = Seat::factory()->create([
            'aircraft_id' => $coupon->flight->aircraft_id,
            'seat_no' => $row.'A',
            'cabin_class' => $cabin,
        ]);
        SeatAssignment::create([
            'flight_id' => $coupon->flight_id,
            'seat_id' => $seat->id,
            'passenger_id' => $coupon->passenger_id,
            'coupon_id' => $coupon->id,
        ]);
    }

    private function withFiveKgExcess(Passenger $passenger): int
    {
        $this->bags->add($passenger, 28, BagType::Standard, $this->agent);
        $this->bags->add($passenger, 23, BagType::Standard, $this->agent);

        return $this->bags->excess($passenger->fresh())->quote->totalCents;
    }

    private function businessSeat(Flight $flight, string $seatNo = '1A'): Seat
    {
        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => $seatNo,
            'cabin_class' => CabinClass::Business,
        ]);
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function payExcess(Passenger $passenger, array $card = []): TestResponse
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.excess.pay', $passenger), array_merge([
                'card_number' => self::FULL_CARD,
                'card_expiry' => '12/30',
                'card_cvc' => self::CVC,
                'card_name' => 'Rami Haddad',
            ], $card));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function payUpgrade(Passenger $passenger, Seat $seat, array $extra = []): TestResponse
    {
        $params = isset($extra['flight_id'])
            ? [$passenger, 'flight' => $extra['flight_id']]
            : [$passenger];

        return $this->from(route('checkin.passengers.upgrade', $params))
            ->post(route('checkin.passengers.upgrade.store', $passenger), array_merge([
                'cabin_class' => CabinClass::Business->value,
                'seat_id' => $seat->id,
                'card_number' => self::FULL_CARD,
                'card_expiry' => '12/30',
                'card_cvc' => self::CVC,
                'card_name' => 'Rami Haddad',
            ], $extra));
    }

    private function assertNoCardSecretsAnywhere(TestResponse $response): void
    {
        // Full PANs only on the page (a 3-digit CVC can appear inside MySQL auto-increment ids in URLs).
        $cardNumbers = [self::FULL_CARD, self::FULL_DIGITS, self::DECLINED, self::DECLINED_DIGITS];
        $body = $response->getContent() ?: '';
        foreach ($cardNumbers as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // Full PANs must not sit in the session (CVC is too short: MySQL ids appear in _previous urls).
        $session = json_encode(session()->all()) ?: '';
        foreach ($cardNumbers as $secret) {
            $this->assertStringNotContainsString($secret, $session);
        }
        $this->assertArrayNotHasKey('card_number', session()->getOldInput());
        $this->assertArrayNotHasKey('card_cvc', session()->getOldInput());
        $this->assertArrayNotHasKey('card_expiry', session()->getOldInput());

        foreach (DB::select('SELECT * FROM payments') as $row) {
            $blob = json_encode($row) ?: '';
            foreach ($cardNumbers as $secret) {
                $this->assertStringNotContainsString($secret, $blob);
            }
            $this->assertObjectNotHasProperty('card_number', $row);
            $this->assertObjectNotHasProperty('card_cvc', $row);
            $this->assertObjectNotHasProperty('cvc', $row);
            $this->assertSame(4, strlen((string) $row->card_last4));
        }

        foreach (DB::select('SELECT * FROM audit_log') as $row) {
            $blob = json_encode($row) ?: '';
            foreach ($cardNumbers as $secret) {
                $this->assertStringNotContainsString($secret, $blob);
            }
        }

        $logPath = storage_path('logs/laravel.log');
        if (File::exists($logPath)) {
            $log = File::get($logPath);
            foreach ($cardNumbers as $secret) {
                $this->assertStringNotContainsString($secret, $log);
            }
        }
    }

    public function test_excess_payment_never_keeps_full_card_or_cvc_and_receipt_shows_brand_last4_reference(): void
    {
        Log::spy();
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);

        $response = $this->payExcess($passenger)->assertSessionHas('success');
        $this->assertNoCardSecretsAnywhere($response);

        $payment = Payment::query()->where('passenger_id', $passenger->id)->where('kind', PaymentKind::ExcessBaggage)->sole();
        $this->assertSame(4, strlen((string) $payment->card_last4));
        $this->assertSame('4242', $payment->card_last4);
        $this->assertNotEmpty($payment->card_brand);
        $this->assertNotEmpty($payment->gateway_reference);

        $page = $this->get(route('checkin.passengers.show', $passenger))->assertOk();
        $page->assertSee('••••'.$payment->card_last4, false);
        $page->assertSee($payment->gateway_reference, false);
        $page->assertSee($payment->card_brand, false);
        $this->assertNoCardSecretsAnywhere($page);

        Log::shouldNotHaveReceived('info', fn (...$args) => str_contains(json_encode($args) ?: '', self::FULL_DIGITS));
        Log::shouldNotHaveReceived('debug', fn (...$args) => str_contains(json_encode($args) ?: '', self::FULL_DIGITS));
        Log::shouldNotHaveReceived('error', fn (...$args) => str_contains(json_encode($args) ?: '', self::FULL_DIGITS));
    }

    public function test_group_excess_payment_never_keeps_full_card_or_cvc(): void
    {
        $reservation = Reservation::factory()->for($this->flight)->create(['pnr' => 'GRPSEC']);
        $passenger = Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy,
            'first_name' => 'Rami',
            'last_name' => 'One',
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger, '15C');
        $this->withFiveKgExcess($passenger);

        $this->get(route('checkin.reservations.group', $reservation))
            ->assertOk()
            ->assertSee(route('checkin.excess.pay', $passenger), false);

        $response = $this->from(route('checkin.reservations.group', $reservation))
            ->post(route('checkin.excess.pay', $passenger), [
                'card_number' => self::FULL_CARD,
                'card_expiry' => '12/30',
                'card_cvc' => self::CVC,
            ])->assertSessionHas('success');

        $this->assertNoCardSecretsAnywhere($response);
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Succeeded)->count());
    }

    public function test_upgrade_payment_never_keeps_full_card_or_cvc_and_receipt_fields_only(): void
    {
        Log::spy();
        $passenger = $this->passenger();
        $business = $this->businessSeat($this->flight);

        $response = $this->payUpgrade($passenger, $business)->assertSessionHas('success');
        $this->assertNoCardSecretsAnywhere($response);

        $payment = Payment::query()->where('passenger_id', $passenger->id)->where('kind', PaymentKind::Upgrade)->sole();
        $this->assertSame('4242', $payment->card_last4);
        $this->assertNotEmpty($payment->card_brand);
        $this->assertNotEmpty($payment->gateway_reference);

        $page = $this->get(route('checkin.passengers.show', $passenger))->assertOk();
        $this->assertNoCardSecretsAnywhere($page);
    }

    public function test_declined_excess_changes_nothing(): void
    {
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);
        $dueBefore = $this->bags->excessDueCents($passenger->fresh());

        $response = $this->payExcess($passenger, ['card_number' => self::DECLINED])
            ->assertSessionHas('error', 'Card declined');
        $this->assertNoCardSecretsAnywhere($response);

        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Succeeded)->count());
        $this->assertSame($dueBefore, $this->bags->excessDueCents($passenger->fresh()));
        $this->assertGreaterThan(0, $dueBefore);
    }

    public function test_declined_upgrade_changes_nothing(): void
    {
        $passenger = $this->passenger();
        $oldSeat = $passenger->seatAssignment->seat->seat_no;
        $business = $this->businessSeat($this->flight);

        $response = $this->payUpgrade($passenger, $business, ['card_number' => self::DECLINED])
            ->assertSessionHas('error', 'Card declined');
        $this->assertNoCardSecretsAnywhere($response);

        $passenger->refresh();
        $this->assertSame(CabinClass::Economy, $passenger->cabin_class);
        $this->assertSame($oldSeat, $passenger->seatAssignment->seat->seat_no);
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Succeeded)->count());
    }

    public function test_group_declined_excess_changes_nothing(): void
    {
        $reservation = Reservation::factory()->for($this->flight)->create(['pnr' => 'GRPDCL']);
        $passenger = Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy,
            'first_name' => 'Lara',
            'last_name' => 'Two',
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger, '16C');
        $this->withFiveKgExcess($passenger);
        $dueBefore = $this->bags->excessDueCents($passenger->fresh());

        $this->from(route('checkin.reservations.group', $reservation))
            ->post(route('checkin.excess.pay', $passenger), [
                'card_number' => self::DECLINED,
                'card_expiry' => '12/30',
                'card_cvc' => self::CVC,
            ])->assertSessionHas('error', 'Card declined');

        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Succeeded)->count());
        $this->assertSame($dueBefore, $this->bags->excessDueCents($passenger->fresh()));
    }

    public function test_connecting_upgrade_is_charged_once_and_recorded_on_the_right_flight(): void
    {
        $passenger = $this->connectingPassenger();
        $first = $passenger->reservation->flight;
        $second = $passenger->coupons->first(fn (Coupon $c) => $c->flight_id !== $first->id)->flight;
        $oldFirstSeat = $passenger->couponOn($first)->seatAssignment->seat->seat_no;
        $business = $this->businessSeat($second, '2B');

        $this->payUpgrade($passenger, $business, ['flight_id' => $second->id])
            ->assertSessionHas('success');

        $payments = Payment::query()
            ->where('passenger_id', $passenger->id)
            ->where('kind', PaymentKind::Upgrade)
            ->get();
        $this->assertCount(1, $payments);
        $this->assertSame(PaymentStatus::Succeeded, $payments->first()->status);

        $passenger = $passenger->fresh();
        $this->assertSame($oldFirstSeat, $passenger->couponOn($first)->seatAssignment->seat->seat_no);
        $this->assertSame('2B', $passenger->couponOn($second)->seatAssignment->seat->seat_no);

        $paymentAudit = AuditEntry::query()
            ->where('passenger_id', $passenger->id)
            ->where('field', 'payment')
            ->where('new_value', 'like', '%Upgrade%')
            ->sole();
        $this->assertSame($second->id, $paymentAudit->flight_id);

        $upgradeAudit = AuditEntry::query()
            ->where('passenger_id', $passenger->id)
            ->where('field', 'upgrade')
            ->sole();
        $this->assertSame($second->id, $upgradeAudit->flight_id);
    }

    public function test_gate_and_reservation_get_403_on_checkin_payment_routes(): void
    {
        $passenger = $this->passenger();
        $this->withFiveKgExcess($passenger);
        $business = $this->businessSeat($this->flight);

        foreach ([Role::Gate, Role::Reservation] as $role) {
            $this->actingAsRole($role);

            $this->post(route('checkin.excess.pay', $passenger), [
                'card_number' => self::FULL_CARD,
                'card_expiry' => '12/30',
                'card_cvc' => self::CVC,
            ])->assertForbidden();

            $this->get(route('checkin.passengers.upgrade', $passenger))->assertForbidden();
            $this->post(route('checkin.passengers.upgrade.store', $passenger), [
                'cabin_class' => CabinClass::Business->value,
                'seat_id' => $business->id,
                'card_number' => self::FULL_CARD,
                'card_expiry' => '12/30',
                'card_cvc' => self::CVC,
            ])->assertForbidden();
        }

        $this->assertSame(0, Payment::count());
    }
}
