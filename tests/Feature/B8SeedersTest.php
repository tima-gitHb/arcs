<?php

namespace Tests\Feature;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\BaggageAllowance;
use App\Models\BoardingPass;
use App\Models\EntryRule;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\Setting;
use App\Models\SpecialService;
use App\Models\TripFare;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B8SeedersTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $classicFlights;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classicFlights = [
            $this->flightNo('110'),
            $this->flightNo('201'),
            $this->flightNo('202'),
            $this->flightNo('203'),
            $this->flightNo('204'),
            $this->flightNo('216'),
            $this->flightNo('264'),
            $this->flightNo('305'),
            $this->flightNo('422'),
        ];

        $this->seed();
    }

    /**
     * @return array<string, int> passenger count per status on one flight
     */
    private function statusCounts(string $flightNo): array
    {
        return Flight::where('flight_no', $flightNo)->firstOrFail()
            ->passengers()
            ->get()
            ->countBy(fn (Passenger $p) => $p->status->value)
            ->sortKeys()
            ->all();
    }

    public function test_each_role_can_log_in(): void
    {
        foreach (Role::cases() as $role) {
            $this->post('/login', ['email' => $role->value.'@arcs.test', 'password' => 'password'])
                ->assertRedirect(route($role->homeRoute(), absolute: false));

            $this->assertSame($role, auth()->user()->role);
            $this->post('/logout');
        }
    }

    public function test_aircraft_settings_and_allowances(): void
    {
        $this->assertSame(6, Aircraft::count());
        $this->assertSame(1014, Seat::count());
        $this->assertSame(84, Seat::where('cabin_class', 'Business')->count());
        $this->assertSame('6', Setting::find('passport_min_months')->value);
        $this->assertSame(132, Seat::where('cabin_class', 'Premium')->count());
        $this->assertSame(798, Seat::where('cabin_class', 'Economy')->count());
        $this->assertSame(3, Aircraft::where('registration', 'OD-ARA')->value('premium_rows'));
        $this->assertSame('32', Setting::find('hard_max_kg_per_bag')->value);
        $this->assertSame(2, BaggageAllowance::where('cabin_class', 'Economy')->value('pieces'));
        $this->assertSame(2, BaggageAllowance::where('cabin_class', 'Premium')->value('pieces'));
        $this->assertSame(2, BaggageAllowance::where('cabin_class', 'Business')->value('pieces'));
    }

    public function test_airport_connection_times_are_seeded(): void
    {
        $this->assertSame(90, Airport::find('CDG')->min_connection_minutes);
        $this->assertSame('Paris–Charles de Gaulle', Airport::find('CDG')->name);
        $this->assertSame(45, Airport::find('BEY')->min_connection_minutes);
        $this->assertSame(60, Airport::find('IST')->min_connection_minutes);
    }

    public function test_at_least_thirty_five_reservations_exist(): void
    {
        $this->assertGreaterThanOrEqual(35, Reservation::count());
    }

    public function test_trip_fares_cover_bey_destinations(): void
    {
        $this->assertSame(9, TripFare::query()->where('origin_code', 'BEY')->count());
        $trip = TripFare::query()->forTrip('BEY', 'CDG')->where('passenger_type', PassengerType::Adult)->firstOrFail();
        $this->assertSame(34000, $trip->one_way_cents);
        $this->assertSame(59500, $trip->round_trip_cents);
    }

    public function test_flight_statuses(): void
    {
        $statuses = Flight::query()
            ->whereIn('flight_no', $this->classicFlights)
            ->pluck('status', 'flight_no')
            ->map->value
            ->sortKeys()
            ->all();

        $this->assertSame([
            $this->flightNo('110') => 'Departed',
            $this->flightNo('201') => 'CheckinOpen',
            $this->flightNo('202') => 'CheckinOpen',
            $this->flightNo('203') => 'Scheduled',
            $this->flightNo('204') => 'Scheduled',
            $this->flightNo('216') => 'Scheduled',
            $this->flightNo('264') => 'CheckinOpen',
            $this->flightNo('305') => 'Boarding',
            $this->flightNo('422') => 'Scheduled',
        ], $statuses);

        $this->assertNotNull(Flight::where('flight_no', $this->flightNo('110'))->value('boarding_closed_at'));
    }

    public function test_flight_origin_and_destination(): void
    {
        $routes = Flight::query()
            ->whereIn('flight_no', $this->classicFlights)
            ->get()
            ->mapWithKeys(fn (Flight $f) => [$f->flight_no => $f->origin.'-'.$f->destination])
            ->sortKeys()
            ->all();

        $this->assertSame([
            $this->flightNo('110') => 'BEY-LHR',
            $this->flightNo('201') => 'BEY-CDG',
            $this->flightNo('202') => 'CDG-BEY',
            $this->flightNo('203') => 'BEY-CDG',
            $this->flightNo('204') => 'CDG-BEY',
            $this->flightNo('216') => 'IST-CDG',
            $this->flightNo('264') => 'BEY-IST',
            $this->flightNo('305') => 'BEY-DXB',
            $this->flightNo('422') => 'BEY-FCO',
        ], $routes);
    }

    public function test_passenger_counts_per_status_match_the_list(): void
    {
        $this->assertSame(['Booked' => 9, 'Cancelled' => 1, 'CheckedIn' => 4], $this->statusCounts($this->flightNo('201')));
        $this->assertSame(['Boarded' => 5, 'Booked' => 1, 'CheckedIn' => 3], $this->statusCounts($this->flightNo('305')));
        $this->assertSame(['Boarded' => 6, 'NoShow' => 2], $this->statusCounts($this->flightNo('110')));
        $this->assertSame(['Booked' => 6], $this->statusCounts($this->flightNo('422')));
    }

    public function test_paris_special_cases(): void
    {
        $paris = Flight::where('flight_no', $this->flightNo('201'))->firstOrFail();

        $checkedIn = $paris->passengers()->wherePivot('status', 'CheckedIn')->get();
        foreach ($checkedIn as $passenger) {
            if ($passenger->type === PassengerType::Infant) {
                $this->assertNull($passenger->seatAssignment);

                continue;
            }
            $this->assertNotNull($passenger->seatAssignment);
            $this->assertGreaterThanOrEqual(1, $passenger->bags->count());
        }

        $cancelled = $paris->passengers()->wherePivot('status', 'Cancelled')->sole();
        $this->assertNull($cancelled->seatAssignment);

        $infant = $paris->passengers()->where('type', PassengerType::Infant)->sole();
        $this->assertNotNull($infant->accompanyingAdult);
        $this->assertSame($infant->reservation_id, $infant->accompanyingAdult->reservation_id);
        $this->assertNull($infant->seatAssignment);

        $this->assertTrue($paris->passengers()->whereHas('travelDocuments', fn ($q) => $q->where('type', 'Visa'))->exists());
    }

    public function test_SR43_SR44_paris_special_bags_and_services(): void
    {
        $paris = Flight::where('flight_no', $this->flightNo('201'))->firstOrFail();
        $ids = $paris->passengers()->pluck('passengers.id');
        $bags = Bag::whereIn('passenger_id', $ids)->get();

        $infant = $paris->passengers()->where('type', PassengerType::Infant)->sole();
        $stroller = $bags->where('type', BagType::Stroller)->sole();
        $this->assertSame($infant->accompanying_adult_id, $stroller->passenger_id);
        $this->assertSame(BagHandling::GateDelivery, $stroller->handling);

        $wchr = SpecialService::whereIn('passenger_id', $ids)->where('type', SpecialServiceCode::WCHR)->sole();
        $wheelchair = $bags->where('type', BagType::Wheelchair)->sole();
        $this->assertSame($wchr->passenger_id, $wheelchair->passenger_id);
        $this->assertSame(BagHandling::GateDelivery, $wheelchair->handling);

        $this->assertCount(1, $bags->where('type', BagType::SportsEquipment));

        $codes = SpecialService::whereIn('passenger_id', $ids)->pluck('type')->map->value->sort()->values()->all();
        $this->assertSame(['BLND', 'PETC', 'WCHR'], $codes);
        $this->assertSame('Cat, 6 kg with carrier', SpecialService::where('type', SpecialServiceCode::PETC)->value('details'));
    }

    public function test_expired_passport_passenger_is_refused_at_check_in(): void
    {
        $paris = Flight::where('flight_no', $this->flightNo('201'))->firstOrFail();
        $expired = $paris->passengers()
            ->whereHas('travelDocuments', fn ($q) => $q->where('type', 'Passport')->where('expiry_date', '<', now()))
            ->sole();

        $this->expectExceptionMessage('Document expired');
        app(StatusService::class)->checkIn($expired, User::where('role', Role::Checkin)->first());
    }

    public function test_no_show_bags_exist_on_london(): void
    {
        $london = Flight::where('flight_no', $this->flightNo('110'))->firstOrFail();
        $noShowIds = $london->passengers()->wherePivot('status', 'NoShow')->pluck('passengers.id');

        $this->assertSame(2, Bag::whereIn('passenger_id', $noShowIds)->count());
    }

    public function test_audit_log_is_filled_through_status_service(): void
    {
        $this->assertGreaterThan(0, AuditEntry::count());

        $changed = Passenger::whereHas('coupons', fn ($coupon) => $coupon->where('status', '!=', PassengerStatus::Booked))->count();
        $auditedPassengers = AuditEntry::query()->whereNotNull('passenger_id')->distinct()->count('passenger_id');
        $this->assertGreaterThanOrEqual($changed, $auditedPassengers);
        $this->assertGreaterThan(0, $changed);
    }

    public function test_every_checked_in_or_boarded_passenger_has_exactly_one_active_pass(): void
    {
        $passengers = Passenger::whereHas('coupons', fn ($coupon) => $coupon->whereIn('status', [PassengerStatus::CheckedIn, PassengerStatus::Boarded]))->get();

        $this->assertGreaterThan(0, $passengers->count());

        foreach ($passengers as $passenger) {
            $couponCount = $passenger->coupons()
                ->whereIn('status', [PassengerStatus::CheckedIn, PassengerStatus::Boarded])
                ->count();
            $this->assertSame(
                $couponCount,
                $passenger->boardingPasses()->whereNull('voided_at')->count(),
                "Passenger {$passenger->id}",
            );
        }

        $this->assertSame(
            BoardingPass::count(),
            AuditEntry::where('field', 'boarding_pass_issued')->count(),
        );
    }

    public function test_fares_fees_and_payments_are_seeded(): void
    {
        $this->assertSame(9, Fare::query()->defaults()->count());
        $this->assertGreaterThan(9, Fare::query()->whereNotNull('origin_airport_code')->count());
        $this->assertSame(4, Fee::count());
        $this->assertSame(5000, Fee::where('code', 'ExtraBag')->value('amount_cents'));

        $this->assertTrue(Payment::where('status', PaymentStatus::Failed)->where('kind', PaymentKind::Fare)->exists());
        $succeeded = Payment::where('status', PaymentStatus::Succeeded)->where('kind', PaymentKind::Fare)->first();
        $this->assertNotNull($succeeded);
        $this->assertSame($this->flightNo('201'), $succeeded->reservation->flight->flight_no);
        $this->assertSame('4242', $succeeded->card_last4);
        $this->assertGreaterThan(0, $succeeded->amount_cents);
    }

    public function test_in_tests_the_entry_rule_seeder_loads_the_small_fixture(): void
    {
        $this->assertSame(7, EntryRule::count());
        $this->assertSame('visa required', EntryRule::where(['passport_country' => 'LB', 'destination_country' => 'FR'])->value('requirement'));
    }

    public function test_every_pnr_is_unique(): void
    {
        $pnrs = Reservation::pluck('pnr');

        $this->assertGreaterThan(0, $pnrs->count());
        $this->assertSame($pnrs->count(), $pnrs->unique()->count());
        $pnrs->each(fn ($pnr) => $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $pnr));
    }

    public function test_seeded_flights_have_departure_times_relative_to_now(): void
    {
        $paris = Flight::where('flight_no', $this->flightNo('201'))->firstOrFail();

        $this->assertSame(FlightStatus::CheckinOpen, $paris->status);
        $this->assertTrue(now()->between($paris->checkin_opens_at, $paris->checkin_closes_at));
        $this->assertTrue($paris->departure_at->isFuture());
    }

    public function test_schedule_includes_midnight_beirut_departure_tomorrow(): void
    {
        $midnight = Flight::query()
            ->where('origin', 'BEY')
            ->where('destination', 'CAI')
            ->whereBetween('departure_at', [
                now('Asia/Beirut')->addDay()->startOfDay()->utc(),
                now('Asia/Beirut')->addDay()->startOfDay()->addHour()->utc(),
            ])
            ->first();

        $this->assertNotNull($midnight, 'Expected a BEY→CAI flight departing around 00:00 Beirut local tomorrow.');
    }
}
