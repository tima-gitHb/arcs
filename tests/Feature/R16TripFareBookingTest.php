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
use App\Models\TripFare;
use App\Support\Money;
use Database\Seeders\RouteFareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Booking price uses the trip fare (origin → final destination) when published (CR-13 / SR-69);
 * round trip uses round_trip_cents; otherwise today's per-route sum (R12).
 */
class R16TripFareBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->seed(RouteFareSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
    }

    public function test_SR69_bey_ist_cdg_is_priced_as_one_trip_fare(): void
    {
        TripFare::query()->create([
            'origin_code' => 'BEY',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 35000,
            'round_trip_cents' => null,
        ]);

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

        $this->actingAsRole('reservation');
        $response = $this->post(route('reservation.store'), [
            'journey_type' => 'connection',
            'outbound_flight_ids' => [$first->id, $second->id],
            'passengers' => [
                $this->passenger([
                    'seats' => [
                        $first->id => $seatOut,
                        $second->id => $seatIn,
                    ],
                ]),
            ],
            ...$this->paidBookingFields(),
        ]);

        $reservation = Reservation::query()->with('payments')->sole();
        $payment = $reservation->payments->sole();

        $response->assertRedirect(route('reservation.confirmation', $reservation));
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        // Trip 350 — not the route sum BEY→IST 190 + IST→CDG 210 = 400.
        $this->assertSame(35000, $payment->amount_cents);
        $this->followRedirects($response)->assertOk()->assertSee(Money::format(35000));
    }

    public function test_SR69_round_trip_uses_the_round_trip_fare(): void
    {
        TripFare::query()->create([
            'origin_code' => 'BEY',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 40000,
            'round_trip_cents' => 70000,
        ]);

        $out = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:45', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $back = Flight::factory()->create([
            'flight_no' => $this->flightNo('204'),
            'origin' => 'CDG',
            'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-18 14:15', 'UTC'),
        ]);

        $this->actingAsRole('reservation');
        $response = $this->post(route('reservation.store'), [
            'journey_type' => 'round_trip',
            'outbound_flight_ids' => [$out->id],
            'return_flight_ids' => [$back->id],
            'passengers' => [
                $this->passenger([
                    'seats' => [
                        $out->id => $this->economySeat($out),
                        $back->id => $this->economySeat($back),
                    ],
                ]),
            ],
            ...$this->paidBookingFields(),
        ]);

        $reservation = Reservation::query()->with('payments')->sole();
        $response->assertRedirect(route('reservation.confirmation', $reservation));
        $this->assertSame(70000, $reservation->payments->sole()->amount_cents);
        $this->followRedirects($response)->assertOk()->assertSee(Money::format(70000));
    }

    public function test_SR69_without_a_trip_fare_the_booking_uses_the_route_sum(): void
    {
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

        $this->actingAsRole('reservation');
        $this->post(route('reservation.store'), [
            'journey_type' => 'connection',
            'outbound_flight_ids' => [$first->id, $second->id],
            'passengers' => [
                $this->passenger([
                    'seats' => [
                        $first->id => $this->economySeat($first),
                        $second->id => $this->economySeat($second),
                    ],
                ]),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        // BEY→IST 190 + IST→CDG 210
        $this->assertSame(40000, Reservation::query()->sole()->payments()->sole()->amount_cents);
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
            'date_of_birth' => '1985-04-12',
            'extra_bags' => 0,
            'extra_kg' => 0,
            'passport' => [
                'number' => 'LR1234567',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ], $overrides);
    }

    private function economySeat(Flight $flight): int
    {
        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12'.chr(65 + (Seat::query()->where('aircraft_id', $flight->aircraft_id)->count() % 6)),
            'cabin_class' => 'Economy',
        ])->id;
    }
}
