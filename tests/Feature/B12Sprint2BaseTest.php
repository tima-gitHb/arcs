<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\FareNotFound;
use App\Models\AuditEntry;
use App\Models\BaggageAllowance;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AircraftService;
use App\Services\AllowanceService;
use App\Services\BagService;
use App\Services\FareService;
use App\Services\Payments\CardDetails;
use App\Services\Payments\FakeCardGateway;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\ReservationContactRules;
use Database\Seeders\FareSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class B12Sprint2BaseTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD_CARD = '4242 4242 4242 4242';

    private const DECLINED_CARD = '4000 0000 0000 0002';

    private function card(string $number = self::GOOD_CARD, string $expiry = '12/30', string $cvc = '123'): CardDetails
    {
        return new CardDetails($number, $expiry, $cvc);
    }

    private function passenger(array $attributes = []): Passenger
    {
        return Passenger::factory()->create($attributes);
    }

    private function seatIn(Passenger $passenger, CabinClass $cabin): void
    {
        $flight = $passenger->reservation->flight;
        $seat = Seat::factory()->create(['aircraft_id' => $flight->aircraft_id, 'cabin_class' => $cabin]);
        SeatAssignment::create(['flight_id' => $flight->id, 'seat_id' => $seat->id, 'passenger_id' => $passenger->id]);
    }

    // --- 1. Local time -------------------------------------------------------------------------

    public function test_the_app_and_mysql_use_beirut_time(): void
    {
        $this->assertSame('Asia/Beirut', config('app.timezone'));

        $offset = now('Asia/Beirut')->format('P');
        $this->assertSame($offset, config('database.connections.mysql.timezone'));

        if (DB::getDriverName() === 'mysql') {
            $this->assertSame($offset, DB::selectOne('select @@session.time_zone as tz')->tz);
        }
    }

    public function test_beirut_time_handles_summer_and_winter(): void
    {
        $this->assertSame('15:00', Carbon::parse('2026-07-01 12:00', 'UTC')->setTimezone(config('app.timezone'))->format('H:i'));
        $this->assertSame('14:00', Carbon::parse('2026-01-15 12:00', 'UTC')->setTimezone(config('app.timezone'))->format('H:i'));
    }

    public function test_today_means_today_in_beirut(): void
    {
        // 22:30 UTC on the 10th is already 00:30 on the 11th in Beirut.
        $this->travelTo(Carbon::parse('2026-11-10 22:30', 'UTC'));
        Flight::factory()->create(['flight_no' => 'ME111', 'departure_at' => Carbon::parse('2026-11-11 08:00', 'Asia/Beirut')]);
        Flight::factory()->create(['flight_no' => 'ME100', 'departure_at' => Carbon::parse('2026-11-10 20:00', 'Asia/Beirut')]);

        $this->blade('<x-todays-flights role="gate" />')
            ->assertSee('ME111')
            ->assertSee('08:00')
            ->assertDontSee('ME100');
    }

    // --- 2. Premium class ----------------------------------------------------------------------

    public function test_premium_sits_between_business_and_economy(): void
    {
        $this->assertSame(['Business', 'Premium', 'Economy'], array_column(CabinClass::cases(), 'value'));
        $this->assertSame('Premium', CabinClass::Premium->label());
    }

    public function test_aircraft_service_generates_premium_seats_after_the_business_rows(): void
    {
        $aircraft = app(AircraftService::class)->create([
            'registration' => 'OD-PRM', 'model' => 'A321', 'rows' => 10, 'seats_per_row' => 2,
            'business_rows' => 2, 'premium_rows' => 3,
        ]);

        $this->assertSame(3, $aircraft->fresh()->premium_rows);

        $cabin = fn (string $no) => $aircraft->seats()->where('seat_no', $no)->value('cabin_class');
        $this->assertSame('Business', $this->cabinValue($cabin('2B')));
        $this->assertSame('Premium', $this->cabinValue($cabin('3A')));
        $this->assertSame('Premium', $this->cabinValue($cabin('5B')));
        $this->assertSame('Economy', $this->cabinValue($cabin('6A')));
        $this->assertSame(6, $aircraft->seats()->where('cabin_class', CabinClass::Premium)->count());
    }

    private function cabinValue(mixed $value): string
    {
        return $value instanceof CabinClass ? $value->value : (string) $value;
    }

    public function test_premium_rows_default_to_zero(): void
    {
        $aircraft = app(AircraftService::class)->create([
            'registration' => 'OD-OLD', 'model' => 'A320', 'rows' => 4, 'seats_per_row' => 2, 'business_rows' => 1,
        ]);

        $this->assertSame(0, $aircraft->fresh()->premium_rows);
        $this->assertSame(0, $aircraft->seats()->where('cabin_class', CabinClass::Premium)->count());
    }

    public function test_business_and_premium_rows_cannot_exceed_the_total(): void
    {
        try {
            app(AircraftService::class)->create([
                'registration' => 'OD-BAD', 'model' => 'A320', 'rows' => 4, 'seats_per_row' => 2,
                'business_rows' => 3, 'premium_rows' => 2,
            ]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('premium_rows', $exception->errors());
        }

        $this->assertDatabaseMissing('aircraft', ['registration' => 'OD-BAD']);
    }

    // --- 3. Allowance per passenger ------------------------------------------------------------

    private function seedAllowances(): void
    {
        $this->seed(SettingSeeder::class);
    }

    public function test_seeded_allowances_and_the_hard_limit(): void
    {
        $this->seedAllowances();

        $rows = BaggageAllowance::all()->mapWithKeys(fn ($a) => [$a->cabin_class->value => [$a->pieces, $a->max_kg_per_bag]])->all();

        $this->assertSame(['Economy' => [2, '23.0'], 'Premium' => [2, '23.0'], 'Business' => [2, '32.0']], collect($rows)->sortKeysDesc()->sortBy(fn ($r, $k) => array_search($k, ['Economy', 'Premium', 'Business']))->all());
        $this->assertSame('32', Setting::find('hard_max_kg_per_bag')->value);
    }

    public function test_allowance_for_a_passenger_with_extras(): void
    {
        $this->seedAllowances();
        $passenger = $this->passenger(['cabin_class' => CabinClass::Premium, 'extra_bags' => 1, 'extra_kg' => 5]);

        $allowance = app(AllowanceService::class)->for($passenger);

        $this->assertSame(CabinClass::Premium, $allowance->cabinClass);
        $this->assertSame(3, $allowance->pieces);
        $this->assertSame(23.0, $allowance->kgPerBag);
        $this->assertSame(74.0, $allowance->totalKg); // 3 x 23 + 5
        $this->assertSame(32.0, $allowance->hardMaxKg);
    }

    public function test_an_infant_uses_the_adults_class(): void
    {
        $this->seedAllowances();
        $adult = $this->passenger(['cabin_class' => CabinClass::Business]);
        $infant = Passenger::factory()->for($adult->reservation)->create([
            'type' => PassengerType::Infant, 'accompanying_adult_id' => $adult->id, 'cabin_class' => CabinClass::Economy,
        ]);

        $allowance = app(AllowanceService::class)->for($infant);

        $this->assertSame(CabinClass::Business, $allowance->cabinClass);
        $this->assertSame(32.0, $allowance->kgPerBag);
    }

    public function test_the_seat_decides_the_class_when_there_is_one(): void
    {
        // Same as C4 before B12: moving to a Business seat gives the Business allowance.
        $this->seedAllowances();
        $passenger = $this->passenger(['cabin_class' => CabinClass::Economy]);
        $this->seatIn($passenger, CabinClass::Business);

        $this->assertSame(CabinClass::Business, app(AllowanceService::class)->for($passenger->fresh())->cabinClass);
    }

    public function test_without_seat_or_booked_class_the_allowance_is_economy(): void
    {
        $this->seedAllowances();

        $this->assertSame(CabinClass::Economy, app(AllowanceService::class)->for($this->passenger())->cabinClass);
    }

    public function test_the_hard_limit_defaults_to_32_and_a_missing_allowance_is_not_set(): void
    {
        $allowance = app(AllowanceService::class)->for($this->passenger());

        $this->assertFalse($allowance->isSet);
        $this->assertSame(32.0, $allowance->hardMaxKg);
    }

    public function test_bag_service_reads_the_allowance_through_allowance_service(): void
    {
        BaggageAllowance::create(['cabin_class' => CabinClass::Economy, 'pieces' => 1, 'max_kg_per_bag' => 23.0]);
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create(['extra_bags' => 1]);
        $agent = User::factory()->create(['role' => Role::Checkin]);

        $bags = app(BagService::class);
        $bags->add($passenger, 20, BagType::Standard, $agent);
        $bags->add($passenger, 15, BagType::Standard, $agent); // covered by extra_bags
        $bags->add($passenger, 15, BagType::Standard, $agent);

        // Economy 1 + prepaid extra_bags 1 → 2 pieces; the 3rd bag is one extra piece.
        $excess = $bags->excess($passenger->fresh());
        $this->assertSame(1, $excess->extraBags);
        $this->assertTrue($excess->hasExcess());
    }

    public function test_the_migration_backfills_the_class_from_the_seat(): void
    {
        $adult = $this->passenger();
        $this->seatIn($adult, CabinClass::Business);
        $infant = Passenger::factory()->for($adult->reservation)->create([
            'type' => PassengerType::Infant, 'accompanying_adult_id' => $adult->id,
        ]);
        $noSeat = $this->passenger();

        $migration = include database_path('migrations/2026_11_06_000002_add_cabin_class_and_extras_to_passengers_table.php');
        $migration->backfill();

        $this->assertSame(CabinClass::Business, $adult->fresh()->cabin_class);
        $this->assertSame(CabinClass::Business, $infant->fresh()->cabin_class);
        $this->assertNull($noSeat->fresh()->cabin_class);
        $this->assertSame(0, $adult->fresh()->extra_bags);
        $this->assertSame(0, $adult->fresh()->extra_kg);
    }

    // --- 4. Reservation contact ----------------------------------------------------------------

    public function test_reservation_contact_fields_and_rules(): void
    {
        $reservation = Reservation::factory()->create(['contact_email' => 'rami@example.com', 'contact_phone' => '+9613123456']);
        $this->assertSame('rami@example.com', $reservation->fresh()->contact_email);
        $this->assertSame('+9613123456', $reservation->fresh()->contact_phone);

        $this->assertNull(Reservation::factory()->create()->fresh()->contact_email);

        $valid = fn (array $data) => Validator::make($data, ReservationContactRules::rules())->passes();

        $this->assertTrue($valid([]));
        $this->assertTrue($valid(['contact_email' => 'a@b.co', 'contact_phone' => '+9613123456']));
        $this->assertTrue($valid(['contact_phone' => '03123456']));
        $this->assertFalse($valid(['contact_email' => 'not-an-email']));
        $this->assertFalse($valid(['contact_phone' => '03-123 456']));
        $this->assertFalse($valid(['contact_phone' => '03+123456']));
    }

    // --- 5. Fares and fees ---------------------------------------------------------------------

    public function test_every_class_and_type_and_every_fee_is_seeded(): void
    {
        $this->seed(FareSeeder::class);

        foreach (CabinClass::cases() as $cabin) {
            foreach (PassengerType::cases() as $type) {
                $this->assertGreaterThan(0, Fare::where(['cabin_class' => $cabin, 'passenger_type' => $type])->value('amount_cents'));
            }
        }
        foreach (FeeCode::cases() as $code) {
            $this->assertGreaterThan(0, Fee::where('code', $code)->value('amount_cents'));
        }
        $this->assertSame(['USD'], Fare::distinct()->pluck('currency')->all());
    }

    public function test_fare_service_quotes_lines_and_a_total_from_the_tables(): void
    {
        Fare::create(['cabin_class' => CabinClass::Economy, 'passenger_type' => PassengerType::Adult, 'amount_cents' => 25000, 'currency' => 'USD']);
        Fare::create(['cabin_class' => CabinClass::Economy, 'passenger_type' => PassengerType::Infant, 'amount_cents' => 2500, 'currency' => 'USD']);
        Fee::create(['code' => FeeCode::ExtraBag, 'amount_cents' => 5000, 'currency' => 'USD']);
        Fee::create(['code' => FeeCode::ExcessKg, 'amount_cents' => 1000, 'currency' => 'USD']);
        Fee::create(['code' => FeeCode::Pet, 'amount_cents' => 6000, 'currency' => 'USD']);

        $passengers = [
            ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Adult],
            ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Adult],
            ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Infant],
        ];

        $quote = app(FareService::class)->quote($passengers, ['extra_bags' => 2, 'extra_kg' => 3, 'fees' => [FeeCode::Pet]]);

        // 2 x 250 + 25 + 2 x 50 + 3 x 10 + 60
        $this->assertSame(71500, $quote->totalCents);
        $this->assertSame('USD', $quote->currency);
        $this->assertSame([50000, 2500, 10000, 3000, 6000], array_column($quote->lines, 'amount_cents'));
        $this->assertSame(2, $quote->lines[0]['quantity']);
        $this->assertSame(25000, $quote->lines[0]['unit_cents']);

        // It only reads the tables: M7 changes a number, the quote follows.
        Fare::where('passenger_type', PassengerType::Infant)->update(['amount_cents' => 3000]);
        $this->assertSame(72000, app(FareService::class)->quote($passengers, ['extra_bags' => 2, 'extra_kg' => 3, 'fees' => [FeeCode::Pet]])->totalCents);
    }

    public function test_a_quote_without_extras_and_a_missing_fare(): void
    {
        Fare::create(['cabin_class' => CabinClass::Business, 'passenger_type' => PassengerType::Adult, 'amount_cents' => 75000, 'currency' => 'USD']);

        $this->assertSame(75000, app(FareService::class)->quote([['cabin_class' => CabinClass::Business, 'type' => PassengerType::Adult]])->totalCents);

        $this->expectException(FareNotFound::class);
        app(FareService::class)->quote([['cabin_class' => CabinClass::Premium, 'type' => PassengerType::Child]]);
    }

    public function test_money_is_formatted_from_cents(): void
    {
        $this->assertSame('$1,234.00', Money::format(123400));
        $this->assertSame('$0.05', Money::format(5));
        $this->assertSame('$0.00', Money::format(0));
        $this->assertSame('$1,000,000.50', Money::format(100000050));
    }

    // --- 6. Payments ---------------------------------------------------------------------------

    public function test_the_fake_gateway_is_bound_and_follows_the_test_cards(): void
    {
        $gateway = app(PaymentGateway::class);
        $this->assertInstanceOf(FakeCardGateway::class, $gateway);

        $approved = $gateway->charge($this->card(), 1000, 'USD');
        $this->assertTrue($approved->approved);
        $this->assertSame('Visa', $approved->brand);
        $this->assertSame('4242', $approved->last4);
        $this->assertNotEmpty($approved->reference);

        $declined = $gateway->charge($this->card(self::DECLINED_CARD), 1000, 'USD');
        $this->assertFalse($declined->approved);
        $this->assertSame('Card declined', $declined->message);

        $this->assertSame('Card declined', $gateway->charge($this->card('5555 5555 5555 4444'), 1000, 'USD')->message);
    }

    public function test_the_fake_gateway_validates_expiry_and_cvc_format(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $gateway = app(PaymentGateway::class);

        foreach (['1230', '13/30', 'ab/cd', '10/26'] as $expiry) {
            $this->assertSame('Invalid expiry date', $gateway->charge($this->card(expiry: $expiry), 1000, 'USD')->message, $expiry);
        }
        foreach (['12', '12345', 'abc'] as $cvc) {
            $this->assertSame('Invalid CVC', $gateway->charge($this->card(cvc: $cvc), 1000, 'USD')->message, $cvc);
        }

        $this->assertTrue($gateway->charge($this->card(expiry: '11/26', cvc: '1234'), 1000, 'USD')->approved);
    }

    public function test_a_successful_charge_stores_only_brand_last4_and_reference_and_is_audited(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $agent = User::factory()->create(['role' => Role::Reservation]);
        $reservation = Reservation::factory()->create();

        $payment = app(PaymentService::class)->charge($this->card(), 25000, PaymentKind::Fare, $agent, reservation: $reservation);

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(PaymentKind::Fare, $payment->kind);
        $this->assertSame(25000, $payment->amount_cents);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('card', $payment->method);
        $this->assertSame('Visa', $payment->card_brand);
        $this->assertSame('4242', $payment->card_last4);
        $this->assertNotEmpty($payment->gateway_reference);
        $this->assertSame($agent->id, $payment->taken_by);
        $this->assertTrue($payment->paid_at->equalTo(now()));

        $entry = AuditEntry::where('field', 'payment')->sole();
        $this->assertNull($entry->passenger_id);
        $this->assertSame($reservation->flight_id, $entry->flight_id);
        $this->assertSame($agent->id, $entry->user_id);
        $this->assertStringContainsString('$250.00', $entry->new_value);
    }

    public function test_a_declined_charge_is_recorded_as_failed_and_audited(): void
    {
        $agent = User::factory()->create(['role' => Role::Checkin]);
        $passenger = $this->passenger();

        $payment = app(PaymentService::class)->charge($this->card(self::DECLINED_CARD), 5000, PaymentKind::ExtraBag, $agent, passenger: $passenger);

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Card declined', $payment->failureMessage);
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->gateway_reference);
        $this->assertSame('0002', $payment->fresh()->card_last4);
        $this->assertSame($passenger->id, AuditEntry::where('field', 'payment')->sole()->passenger_id);
    }

    public function test_a_charge_needs_a_positive_amount_and_something_to_pay_for(): void
    {
        $agent = User::factory()->create();

        try {
            app(PaymentService::class)->charge($this->card(), 0, PaymentKind::Fee, $agent, reservation: Reservation::factory()->create());
            $this->fail('Expected a refusal: amount.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        app(PaymentService::class)->charge($this->card(), 100, PaymentKind::Fee, $agent);
    }

    public function test_if_the_audit_row_fails_no_payment_is_kept(): void
    {
        AuditEntry::creating(fn () => throw new RuntimeException('audit_log unavailable'));

        try {
            app(PaymentService::class)->charge($this->card(), 100, PaymentKind::Fee, User::factory()->create(), reservation: Reservation::factory()->create());
            $this->fail('Expected the audit failure.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_the_full_card_number_and_cvc_appear_nowhere(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context);
        });

        $agent = User::factory()->create();
        $reservation = Reservation::factory()->create();
        $service = app(PaymentService::class);

        $good = $service->charge($this->card(cvc: '987'), 100, PaymentKind::Fee, $agent, reservation: $reservation);
        $bad = $service->charge($this->card(self::DECLINED_CARD, cvc: '987'), 100, PaymentKind::Fee, $agent, reservation: $reservation);

        $everything = json_encode([
            DB::table('payments')->get(),
            DB::table('audit_log')->get(),
            session()->all(),
            $logged,
            $good->toArray(),
            $bad->toArray(),
        ]);
        $logFile = storage_path('logs/laravel.log');
        $everything .= is_file($logFile) ? file_get_contents($logFile) : '';

        foreach (['4242424242424242', self::GOOD_CARD, '4000000000000002', self::DECLINED_CARD, '"987"'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_card_details_cannot_be_dumped_or_serialized(): void
    {
        $card = $this->card();

        $this->assertStringNotContainsString('4242424242424242', print_r($card, true));
        $this->assertStringNotContainsString('123', print_r($card, true));

        $this->expectException(RuntimeException::class);
        serialize($card);
    }

    public function test_card_fields_are_never_flashed_after_a_validation_error(): void
    {
        Route::post('/_b12-card', fn (Request $request) => $request->validate(['amount' => 'required']))->middleware('web');

        $this->from('/')->post('/_b12-card', [
            'card_number' => '4242424242424242', 'card_cvc' => '123', 'card_expiry' => '12/30', 'note' => 'kept',
        ])->assertSessionHas('_old_input.note', 'kept')
            ->assertSessionMissing('_old_input.card_number')
            ->assertSessionMissing('_old_input.card_cvc')
            ->assertSessionMissing('_old_input.card_expiry');
    }

    // --- 7. Audit for flights ------------------------------------------------------------------

    public function test_the_audit_model_records_a_flight_entry_without_a_passenger(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $flight = Flight::factory()->create();
        $manager = User::factory()->create(['role' => Role::Manager]);

        $entry = AuditEntry::forFlight($flight, 'flight_status', 'Scheduled', 'Delayed', $manager);

        $entry->refresh();
        $this->assertNull($entry->passenger_id);
        $this->assertTrue($entry->flight->is($flight));
        $this->assertSame('Delayed', $entry->new_value);
        $this->assertSame($manager->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));
    }

    public function test_the_M5_audit_page_and_filters_work_with_a_flight_only_row(): void
    {
        $flight = Flight::factory()->create(['flight_no' => 'ME777']);
        $other = Flight::factory()->create();
        $staff = User::factory()->create(['role' => Role::Gate, 'name' => 'Joe Saliba']);
        AuditEntry::forFlight($flight, 'flight_status', 'Scheduled', 'Delayed', $staff);

        $manager = $this->actingAsRole('manager');

        $this->get(route('manager.audit'))->assertOk()->assertSee('flight_status')->assertSee('ME777')->assertSee('Joe Saliba');

        $this->get(route('manager.audit', ['flight_id' => $flight->id]))->assertOk()->assertSee('flight_status');
        $this->get(route('manager.audit', ['flight_id' => $other->id]))->assertOk()->assertDontSee('flight_status');
        $this->get(route('manager.audit', ['user_id' => $staff->id]))->assertOk()->assertSee('flight_status');
        $this->get(route('manager.audit', ['user_id' => $manager->id]))->assertOk()->assertDontSee('flight_status');
        $this->get(route('manager.audit', ['passenger' => 'Nobody']))->assertOk()->assertDontSee('flight_status');
        $this->get(route('manager.audit', ['date' => now()->toDateString()]))->assertOk()->assertSee('flight_status');
    }

    // --- Pages B12 must not break --------------------------------------------------------------

    public function test_the_shared_seat_map_shows_premium_with_its_own_class_and_legend(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        Seat::factory()->create(['aircraft_id' => $flight->aircraft_id, 'seat_no' => '6A', 'cabin_class' => CabinClass::Premium]);

        // R1: the seat data and the legend.
        $this->actingAsRole('reservation');
        $seats = $this->getJson(route('reservation.seats', $flight))->assertOk()->json('seats');
        $this->assertSame('Premium', collect($seats)->firstWhere('seat_no', '6A')['cabin']);
        $this->get(route('reservation.create'))->assertOk()->assertSeeInOrder(['Economy', 'Premium', 'Business', 'Selected', 'Taken']);

        // Check-in: same data, same legend.
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        $this->actingAsRole('checkin');
        $response = $this->get(route('checkin.passengers.show', $passenger))->assertOk();
        preg_match('/data-seats="([^"]*)"/', $response->getContent(), $match);
        $this->assertSame('Premium', collect(json_decode(html_entity_decode($match[1]), true))->firstWhere('seat_no', '6A')['cabin']);
        $response->assertSee('bg-violet-200', false);

        // The drawing code has a colour for it, and R1 no longer treats every non-Business seat as Economy.
        $this->assertStringContainsString("premium: 'bg-violet-200", file_get_contents(resource_path('js/seat-map.js')));
        $this->assertStringNotContainsString("seat.cabin === 'Business' ? 'business' : 'economy'", file_get_contents(resource_path('views/reservation/create.blade.php')));
    }

    public function test_the_M4_rules_page_handles_premium(): void
    {
        $this->seedAllowances();
        $this->actingAsRole('manager');

        $this->get(route('manager.rules'))->assertOk()->assertSee('allowances[premium][pieces]', false);

        $this->put(route('manager.rules.update'), [
            'passport_min_months' => 6,
            'allowances' => [
                'economy' => ['pieces' => 2, 'max_kg_per_bag' => 23],
                'premium' => ['pieces' => 3, 'max_kg_per_bag' => 25],
                'business' => ['pieces' => 2, 'max_kg_per_bag' => 32],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, BaggageAllowance::where('cabin_class', CabinClass::Premium)->value('pieces'));
    }
}
