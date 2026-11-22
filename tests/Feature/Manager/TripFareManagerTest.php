<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TripFare;
use App\Services\FareService;
use App\Services\JourneyService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TripFareManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->seedBookingPrices();
        $this->actingAsRole(Role::Manager);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'origin_code' => 'BEY',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy->value,
            'passenger_type' => PassengerType::Adult->value,
            'one_way' => '400.00',
            'round_trip' => '700.00',
        ];
    }

    public function test_manager_can_create_a_trip_fare(): void
    {
        $this->post(route('manager.trip-fares.store'), $this->valid())
            ->assertRedirect(route('manager.fares', ['tab' => 'trips']))
            ->assertSessionHasNoErrors();

        $fare = TripFare::query()->sole();
        $this->assertSame('BEY', $fare->origin_code);
        $this->assertSame('CDG', $fare->destination_code);
        $this->assertSame(CabinClass::Economy, $fare->cabin_class);
        $this->assertSame(PassengerType::Adult, $fare->passenger_type);
        $this->assertSame(40000, $fare->one_way_cents);
        $this->assertSame(70000, $fare->round_trip_cents);
        $this->assertSame(1, AuditEntry::count());
        $this->assertSame('trip BEY-CDG Economy Adult', AuditEntry::sole()->field);
    }

    public function test_manager_can_edit_a_trip_fare(): void
    {
        $fare = TripFare::query()->create([
            'origin_code' => 'BEY',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 40000,
            'round_trip_cents' => null,
        ]);

        $this->put(route('manager.trip-fares.update', $fare), $this->valid(['one_way' => '450.00', 'round_trip' => '']))
            ->assertRedirect(route('manager.fares', ['tab' => 'trips']))
            ->assertSessionHasNoErrors();

        $this->assertSame(45000, $fare->fresh()->one_way_cents);
        $this->assertNull($fare->fresh()->round_trip_cents);
    }

    public function test_manager_can_delete_a_trip_fare(): void
    {
        $fare = TripFare::query()->create([
            'origin_code' => 'BEY',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 40000,
        ]);

        $this->delete(route('manager.trip-fares.destroy', $fare))
            ->assertRedirect(route('manager.fares', ['tab' => 'trips']));

        $this->assertSame(0, TripFare::count());
    }

    public function test_trip_fare_validation_refuses_same_airports_zero_amount_and_duplicates(): void
    {
        $this->post(route('manager.trip-fares.store'), $this->valid([
            'destination_code' => 'BEY',
        ]))->assertSessionHasErrors('destination_code');

        $this->post(route('manager.trip-fares.store'), $this->valid([
            'one_way' => '0',
        ]))->assertSessionHasErrors('one_way');

        $this->post(route('manager.trip-fares.store'), $this->valid())->assertSessionHasNoErrors();
        $this->post(route('manager.trip-fares.store'), $this->valid())
            ->assertSessionHasErrors('passenger_type');
    }

    public function test_bey_ist_cdg_quote_uses_trip_fare_when_every_passenger_type_has_one(): void
    {
        foreach (PassengerType::cases() as $type) {
            TripFare::query()->create([
                'origin_code' => 'BEY',
                'destination_code' => 'CDG',
                'cabin_class' => CabinClass::Economy,
                'passenger_type' => $type,
                'one_way_cents' => match ($type) {
                    PassengerType::Adult => 40000,
                    PassengerType::Child => 30000,
                    PassengerType::Infant => 4000,
                },
            ]);
        }

        $first = Flight::factory()->create([
            'flight_no' => $this->flightNo('264'), 'origin' => 'BEY', 'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('216'), 'origin' => 'IST', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:15', 'UTC'),
        ]);
        $reservation = Reservation::factory()->create(['flight_id' => $first->id]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));
        $adult = Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy,
            'type' => PassengerType::Adult,
        ]);
        Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy,
            'type' => PassengerType::Child,
        ]);
        Passenger::factory()->for($reservation)->create([
            'cabin_class' => CabinClass::Economy,
            'type' => PassengerType::Infant,
            'accompanying_adult_id' => $adult->id,
        ]);

        $quote = app(FareService::class)->journeyQuote($reservation->fresh());

        $this->assertSame(74000, $quote->totalCents);
    }

    public function test_trips_tab_lists_trip_fares(): void
    {
        TripFare::query()->create([
            'origin_code' => 'BEY',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 40000,
        ]);

        $this->get(route('manager.fares', ['tab' => 'trips']))
            ->assertOk()
            ->assertSee('BEY → CDG', false)
            ->assertSee('+ New trip fare')
            ->assertSee('every passenger type has one');
    }
}
