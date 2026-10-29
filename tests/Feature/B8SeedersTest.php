<?php

namespace Tests\Feature;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\BaggageAllowance;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\Setting;
use App\Models\SpecialService;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B8SeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->assertSame(180, Seat::count());
        $this->assertSame(30, Seat::where('cabin_class', 'Business')->count());
        $this->assertSame('6', Setting::find('passport_min_months')->value);
        $this->assertSame(1, BaggageAllowance::where('cabin_class', 'Economy')->value('pieces'));
        $this->assertSame(2, BaggageAllowance::where('cabin_class', 'Business')->value('pieces'));
    }

    public function test_flight_statuses(): void
    {
        $statuses = Flight::pluck('status', 'flight_no')->map->value->sortKeys()->all();

        $this->assertSame([
            'ME110' => 'Closed',
            'ME201' => 'CheckinOpen',
            'ME305' => 'Boarding',
            'ME422' => 'Scheduled',
        ], $statuses);

        $this->assertNotNull(Flight::where('flight_no', 'ME110')->value('boarding_closed_at'));
    }

    public function test_flight_origin_and_destination(): void
    {
        $routes = Flight::all()->mapWithKeys(fn (Flight $f) => [$f->flight_no => $f->origin.'-'.$f->destination])->sortKeys()->all();

        $this->assertSame([
            'ME110' => 'BEY-LHR',
            'ME201' => 'BEY-CDG',
            'ME305' => 'BEY-DXB',
            'ME422' => 'BEY-FCO',
        ], $routes);
    }

    public function test_passenger_counts_per_status_match_the_list(): void
    {
        $this->assertSame(['Booked' => 8, 'Cancelled' => 1, 'CheckedIn' => 4], $this->statusCounts('ME201'));
        $this->assertSame(['Boarded' => 5, 'CheckedIn' => 3], $this->statusCounts('ME305'));
        $this->assertSame(['Boarded' => 6, 'NoShow' => 2], $this->statusCounts('ME110'));
        $this->assertSame([], $this->statusCounts('ME422'));
    }

    public function test_paris_special_cases(): void
    {
        $paris = Flight::where('flight_no', 'ME201')->firstOrFail();

        // Checked-in passengers have a seat and at least one bag (except the infant: no seat, no bag).
        $checkedIn = $paris->passengers()->where('passengers.status', 'CheckedIn')->get();
        foreach ($checkedIn as $passenger) {
            if ($passenger->type === PassengerType::Infant) {
                $this->assertNull($passenger->seatAssignment);

                continue;
            }
            $this->assertNotNull($passenger->seatAssignment);
            $this->assertGreaterThanOrEqual(1, $passenger->bags->count());
        }

        // The cancelled passenger's seat was released.
        $cancelled = $paris->passengers()->where('passengers.status', 'Cancelled')->sole();
        $this->assertNull($cancelled->seatAssignment);

        // The infant is linked to an adult on the same booking and has no seat.
        $infant = $paris->passengers()->where('type', PassengerType::Infant)->sole();
        $this->assertNotNull($infant->accompanyingAdult);
        $this->assertSame($infant->reservation_id, $infant->accompanyingAdult->reservation_id);
        $this->assertNull($infant->seatAssignment);

        // Some passengers also have a visa.
        $this->assertTrue($paris->passengers()->whereHas('travelDocuments', fn ($q) => $q->where('type', 'Visa'))->exists());
    }

    public function test_SR43_SR44_paris_special_bags_and_services(): void
    {
        $paris = Flight::where('flight_no', 'ME201')->firstOrFail();
        $ids = $paris->passengers()->pluck('passengers.id');
        $bags = Bag::whereIn('passenger_id', $ids)->get();

        // Stroller for the adult travelling with the infant, delivered at the gate.
        $infant = $paris->passengers()->where('type', PassengerType::Infant)->sole();
        $stroller = $bags->where('type', BagType::Stroller)->sole();
        $this->assertSame($infant->accompanying_adult_id, $stroller->passenger_id);
        $this->assertSame(BagHandling::GateDelivery, $stroller->handling);

        // WCHR passenger with a wheelchair, delivered at the gate.
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
        $paris = Flight::where('flight_no', 'ME201')->firstOrFail();
        $expired = $paris->passengers()
            ->whereHas('travelDocuments', fn ($q) => $q->where('type', 'Passport')->where('expiry_date', '<', now()))
            ->sole();

        $this->expectExceptionMessage('Document expired');
        app(StatusService::class)->checkIn($expired, User::where('role', Role::Checkin)->first());
    }

    public function test_no_show_bags_exist_on_london(): void
    {
        $london = Flight::where('flight_no', 'ME110')->firstOrFail();
        $noShowIds = $london->passengers()->where('passengers.status', 'NoShow')->pluck('passengers.id');

        $this->assertSame(2, Bag::whereIn('passenger_id', $noShowIds)->count());
    }

    public function test_audit_log_is_filled_through_status_service(): void
    {
        $this->assertGreaterThan(0, AuditEntry::count());

        // One audit row per status change: every passenger that is no longer Booked has at least one.
        $changed = Passenger::where('status', '!=', PassengerStatus::Booked)->count();
        $this->assertSame($changed, AuditEntry::distinct()->count('passenger_id'));
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
        $paris = Flight::where('flight_no', 'ME201')->firstOrFail();

        $this->assertSame(FlightStatus::CheckinOpen, $paris->status);
        $this->assertTrue(now()->between($paris->checkin_opens_at, $paris->checkin_closes_at));
        $this->assertTrue($paris->departure_at->isFuture());
    }
}
