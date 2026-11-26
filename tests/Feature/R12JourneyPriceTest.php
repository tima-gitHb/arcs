<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentStatus;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\Seat;
use App\Support\Money;
use Database\Seeders\RouteFareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R12 — journey price (SR-69): sum of each segment's route fare (M14) + extras,
 * shown per segment on the booking quote before payment.
 */
class R12JourneyPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->seed(RouteFareSeeder::class); // BEY→IST Economy adult 190.00
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
    }

    public function test_booking_page_loads_route_fares_for_the_live_quote(): void
    {
        $this->actingAsRole('reservation');

        $page = $this->get(route('reservation.create'));
        preg_match('/const fares = (\[.*?\]);/s', $page->getContent(), $match);

        $this->assertArrayHasKey(1, $match);
        $fares = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

        $route = collect($fares)->first(
            fn (array $fare) => $fare['origin'] === 'BEY'
                && $fare['destination'] === 'IST'
                && $fare['cabin'] === CabinClass::Economy->value
                && $fare['type'] === PassengerType::Adult->value,
        );

        $this->assertNotNull($route);
        $this->assertSame(19000, $route['cents']);

        $page->assertSee('Trip fare origin→destination when published')
            ->assertSee('id="price-lines"', false);
    }

    public function test_SR69_connection_booking_charges_the_sum_of_each_segments_route_fare_plus_extras(): void
    {
        $this->actingAsRole('reservation');

        Fare::query()->updateOrCreate(
            [
                'origin_airport_code' => 'IST',
                'destination_airport_code' => 'CDG',
                'cabin_class' => CabinClass::Economy,
                'passenger_type' => PassengerType::Adult,
            ],
            ['amount_cents' => 21000, 'currency' => 'USD'],
        );

        [$first, $second] = $this->connection(gap: 90);
        $seatOut = $this->economySeat($first);
        $seatIn = $this->economySeat($second);

        $response = $this->post(route('reservation.store'), [
            'journey_type' => 'connection',
            'outbound_flight_ids' => [$first->id, $second->id],
            'passengers' => [
                $this->passenger([
                    'seats' => [
                        $first->id => $seatOut,
                        $second->id => $seatIn,
                    ],
                    'extra_bags' => 1,
                    'extra_kg' => 2,
                ]),
            ],
            ...$this->paidBookingFields(),
        ]);

        $reservation = Reservation::query()->with('payments')->sole();
        $payment = $reservation->payments->sole();

        $response->assertRedirect(route('reservation.confirmation', $reservation));

        // BEY→IST 190 + IST→CDG 210 + extra bag 50 + 2 kg × 10 = 470
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(19000 + 21000 + 5000 + 2000, $payment->amount_cents);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee(Money::format(47000));
    }

    public function test_SR69_one_way_uses_the_route_fare_when_the_route_has_one(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create([
            'flight_no' => $this->flightNo('264'),
            'origin' => 'BEY',
            'destination' => 'IST',
        ]);
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'journey_type' => 'one_way',
            'outbound_flight_ids' => [$flight->id],
            'passengers' => [
                $this->passenger(['seats' => [$flight->id => $seatId]]),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(19000, Reservation::query()->sole()->payments()->sole()->amount_cents);
    }

    public function test_SR69_one_way_falls_back_to_the_default_fare_when_the_route_has_none(): void
    {
        $this->actingAsRole('reservation');
        // GVA has no published route fare in RouteFareSeeder.
        $flight = Flight::factory()->create([
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'GVA',
        ]);
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger(['seat_id' => $seatId]),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(25000, Reservation::query()->sole()->payments()->sole()->amount_cents);
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(int $gap): array
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
            'departure_at' => Carbon::parse('2026-11-15 10:00', 'UTC')->addMinutes($gap),
            'arrives_at' => Carbon::parse('2026-11-15 13:45', 'UTC')->addMinutes($gap),
            'checkin_opens_at' => Carbon::parse('2026-11-15 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 09:15', 'UTC')->addMinutes($gap),
        ]);

        return [$first, $second];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function passenger(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => 'Adult',
            'cabin_class' => 'Economy',
            'date_of_birth' => '1990-05-01',
            'extra_bags' => 0,
            'extra_kg' => 0,
            'passport' => [
                'number' => 'LR8877665',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ], $overrides);
    }

    private function economySeat(Flight $flight): int
    {
        $row = 30 + Seat::query()->where('aircraft_id', $flight->aircraft_id)->count();

        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'cabin_class' => CabinClass::Economy,
            'seat_no' => $row.'A',
        ])->id;
    }
}
