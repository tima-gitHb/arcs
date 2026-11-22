<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
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

/**
 * B14 — trip fares (CR-13, CR-14): origin→final destination price when published, else per-route sum.
 */
class B14TripFaresTest extends TestCase
{
    use RefreshDatabase;

    private const ECONOMY_ADULT = ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Adult];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->seedBookingPrices();
    }

    private function tripFare(string $origin, string $destination, int $oneWay, ?int $roundTrip = null): TripFare
    {
        return TripFare::query()->create([
            'origin_code' => $origin,
            'destination_code' => $destination,
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => $oneWay,
            'round_trip_cents' => $roundTrip,
        ]);
    }

    public function test_B14_trip_fare_is_used_over_the_per_route_sum(): void
    {
        $this->tripFare('BEY', 'CDG', 40000);

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
        Passenger::factory()->for($reservation)->create(['cabin_class' => CabinClass::Economy]);

        $quote = app(FareService::class)->journeyQuote($reservation->fresh());

        $this->assertSame(40000, $quote->totalCents);
    }

    public function test_B14_round_trip_fare_is_used_when_the_return_is_booked(): void
    {
        $this->tripFare('BEY', 'CDG', 40000, 70000);

        $out = Flight::factory()->create([
            'flight_no' => $this->flightNo('201'), 'origin' => 'BEY', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:45', 'UTC'),
        ]);
        $back = Flight::factory()->create([
            'flight_no' => $this->flightNo('204'), 'origin' => 'CDG', 'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-18 14:15', 'UTC'),
        ]);
        $reservation = Reservation::factory()->create(['flight_id' => $out->id]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$out], [$back]));
        Passenger::factory()->for($reservation)->create(['cabin_class' => CabinClass::Economy]);

        $quote = app(FareService::class)->journeyQuote($reservation->fresh());

        $this->assertSame(70000, $quote->totalCents);
    }

    public function test_B14_without_a_trip_fare_the_per_route_sum_is_used(): void
    {
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
        Passenger::factory()->for($reservation)->create(['cabin_class' => CabinClass::Economy]);

        // Defaults: 25000 per flight × 2.
        $quote = app(FareService::class)->journeyQuote($reservation->fresh());

        $this->assertSame(50000, $quote->totalCents);
    }
}
