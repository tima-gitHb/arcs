<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Services\FareService;
use App\Services\JourneyService;
use App\Support\Journey;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * B13b — fares per route (SR-69). A fare with no route is the default; a route fare (origin,
 * destination) replaces it on that route. A journey costs the sum of each flight's route fare.
 */
class B13bRouteFaresTest extends TestCase
{
    use RefreshDatabase;

    private const ECONOMY_ADULT = ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Adult];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->seedBookingPrices(); // defaults: Economy adult 250.00, child 187.50
    }

    private function routeFare(string $origin, string $destination, int $cents, CabinClass $cabin = CabinClass::Economy, PassengerType $type = PassengerType::Adult): Fare
    {
        return Fare::create([
            'origin_airport_code' => $origin,
            'destination_airport_code' => $destination,
            'cabin_class' => $cabin,
            'passenger_type' => $type,
            'amount_cents' => $cents,
            'currency' => 'USD',
        ]);
    }

    public function test_SR69_the_route_fare_is_used_when_the_route_has_one(): void
    {
        $this->routeFare('BEY', 'IST', 18000);

        $quote = app(FareService::class)->quote([self::ECONOMY_ADULT], origin: 'BEY', destination: 'IST');

        $this->assertSame(18000, $quote->totalCents);
    }

    public function test_SR69_the_default_fare_is_used_when_the_route_has_none(): void
    {
        $this->routeFare('BEY', 'IST', 18000);
        $fares = app(FareService::class);

        // Another route, the opposite direction, or no route at all: the default.
        $this->assertSame(25000, $fares->quote([self::ECONOMY_ADULT], origin: 'IST', destination: 'CDG')->totalCents);
        $this->assertSame(25000, $fares->quote([self::ECONOMY_ADULT], origin: 'IST', destination: 'BEY')->totalCents);
        $this->assertSame(25000, $fares->quote([self::ECONOMY_ADULT])->totalCents);

        // Only the class and type that have a route fare change.
        $child = ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Child];
        $this->assertSame(18750, $fares->quote([$child], origin: 'BEY', destination: 'IST')->totalCents);
    }

    public function test_SR69_journey_quote_for_BEY_IST_CDG_is_the_sum_of_each_flights_route_fare(): void
    {
        $this->routeFare('BEY', 'IST', 18000);
        $this->routeFare('IST', 'CDG', 21000);

        $first = Flight::factory()->create([
            'flight_no' => $this->flightNo('264'), 'origin' => 'BEY', 'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'), 'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('216'), 'origin' => 'IST', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'), 'arrives_at' => Carbon::parse('2026-11-15 15:15', 'UTC'),
        ]);
        $reservation = Reservation::factory()->create(['flight_id' => $first->id]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));
        Passenger::factory()->for($reservation)->count(2)->create(['cabin_class' => CabinClass::Economy]);

        $quote = app(FareService::class)->journeyQuote($reservation->fresh());

        // Per passenger: 180.00 (BEY→IST) + 210.00 (IST→CDG); two passengers.
        $this->assertSame(2 * (18000 + 21000), $quote->totalCents);
        $this->assertSame(['BEY→IST', 'IST→CDG'], array_map(fn ($line) => substr($line['label'], 0, strlen('BEY→IST')), $quote->lines));
    }

    public function test_SR69_one_default_and_one_fare_per_route_for_each_class_and_type(): void
    {
        $this->routeFare('BEY', 'IST', 18000);

        // A second default for Economy adult is refused (NULL route still unique).
        try {
            Fare::create(['cabin_class' => CabinClass::Economy, 'passenger_type' => PassengerType::Adult, 'amount_cents' => 1, 'currency' => 'USD']);
            $this->fail('A second default Economy adult fare must be refused.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $this->routeFare('BEY', 'IST', 19000);
    }

    public function test_the_seeder_keeps_the_defaults_and_adds_a_cheaper_BEY_IST_economy_fare(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(9, Fare::query()->defaults()->count());
        $route = Fare::query()
            ->where('origin_airport_code', 'BEY')
            ->where('destination_airport_code', 'IST')
            ->where('cabin_class', CabinClass::Economy)
            ->where('passenger_type', PassengerType::Adult)
            ->sole();
        $this->assertSame(19000, $route->amount_cents);
        $this->assertLessThan(25000, $route->amount_cents);
    }

    public function test_the_manager_fares_page_still_shows_the_default_fares(): void
    {
        $this->routeFare('BEY', 'IST', 18000);
        $this->actingAsRole('manager');

        $this->get(route('manager.fares'))->assertOk()->assertSee('250.00')->assertDontSee('180.00');
    }
}
