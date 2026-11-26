<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentStatus;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\TripFare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Multi-city booking builder (CR-16): 2–6 legs, stopover &gt; 24h between legs, price = sum of trip fares.
 */
class R16MultiCityBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        Mail::fake();
    }

    public function test_SR65_three_leg_booking_is_saved_with_legs_1_2_3(): void
    {
        [$a, $b, $c] = $this->threeLegs();

        $this->actingAsRole('reservation');
        $response = $this->post(route('reservation.store'), $this->bookingPayload([
            [$a->id],
            [$b->id],
            [$c->id],
        ], [
            $a->id => $this->economySeat($a),
            $b->id => $this->economySeat($b),
            $c->id => $this->economySeat($c),
        ]));

        $reservation = Reservation::query()->with(['segments', 'payments', 'passengers'])->sole();
        $response->assertRedirect(route('reservation.confirmation', $reservation));

        $this->assertSame([1, 2, 3], $reservation->segments->pluck('leg')->all());
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $reservation->segments->pluck('flight_id')->all(),
        );
        $this->assertSame(PaymentStatus::Succeeded, $reservation->payments->sole()->status);
    }

    public function test_SR65_a_leg_in_the_past_or_overlapping_is_refused(): void
    {
        $a = $this->legFlight('101', 'BEY', 'IST', '2026-11-15 08:00', '2026-11-15 10:00');
        $overlap = $this->legFlight('102', 'CAI', 'CDG', '2026-11-15 09:00', '2026-11-15 14:00');

        $this->actingAsRole('reservation');
        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), $this->bookingPayload([
                [$a->id],
                [$overlap->id],
            ], [
                $a->id => $this->economySeat($a),
                $overlap->id => $this->economySeat($overlap),
            ]))
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors();

        $this->assertSame(0, Reservation::count());
    }

    public function test_SR65_a_seventh_leg_is_refused(): void
    {
        $ids = [];
        $seats = [];
        $prev = 'BEY';
        $day = 15;
        foreach (['DXB', 'CAI', 'JED', 'RUH', 'AUH', 'DOH', 'IST'] as $i => $dest) {
            $flight = $this->legFlight(
                (string) (200 + $i),
                $prev,
                $dest,
                sprintf('2026-11-%02d 08:00', $day),
                sprintf('2026-11-%02d 14:00', $day),
            );
            $ids[] = [$flight->id];
            $seats[$flight->id] = $this->economySeat($flight);
            $prev = $dest;
            $day += 2;
        }

        $this->actingAsRole('reservation');
        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), $this->bookingPayload($ids, $seats))
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors();

        $this->assertSame(0, Reservation::count());
    }

    public function test_SR69_multicity_price_is_the_sum_of_each_legs_trip_fare(): void
    {
        TripFare::query()->create([
            'origin_code' => 'BEY',
            'destination_code' => 'IST',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 15000,
        ]);
        TripFare::query()->create([
            'origin_code' => 'IST',
            'destination_code' => 'CDG',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 18000,
        ]);
        TripFare::query()->create([
            'origin_code' => 'CDG',
            'destination_code' => 'BEY',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'one_way_cents' => 20000,
        ]);

        [$a, $b, $c] = $this->threeLegs();

        $this->actingAsRole('reservation');
        $this->post(route('reservation.store'), $this->bookingPayload([
            [$a->id],
            [$b->id],
            [$c->id],
        ], [
            $a->id => $this->economySeat($a),
            $b->id => $this->economySeat($b),
            $c->id => $this->economySeat($c),
        ]))->assertRedirect();

        $this->assertSame(15000 + 18000 + 20000, Reservation::query()->sole()->payments()->sole()->amount_cents);
    }

    public function test_SR65_eticket_lists_every_multicity_leg(): void
    {
        [$a, $b, $c] = $this->threeLegs();

        $this->actingAsRole('reservation');
        $response = $this->post(route('reservation.store'), $this->bookingPayload([
            [$a->id],
            [$b->id],
            [$c->id],
        ], [
            $a->id => $this->economySeat($a),
            $b->id => $this->economySeat($b),
            $c->id => $this->economySeat($c),
        ]));

        $reservation = Reservation::query()->sole();
        $page = $this->followRedirects($response);
        $page->assertOk()
            ->assertSee('Leg 1')
            ->assertSee('Leg 2')
            ->assertSee('Leg 3')
            ->assertSee($a->flight_no)
            ->assertSee($b->flight_no)
            ->assertSee($c->flight_no);
    }

    /**
     * @return array{0: Flight, 1: Flight, 2: Flight}
     */
    private function threeLegs(): array
    {
        return [
            $this->legFlight('264', 'BEY', 'IST', '2026-11-15 08:00', '2026-11-15 10:00'),
            $this->legFlight('216', 'IST', 'CDG', '2026-11-18 12:00', '2026-11-18 16:00'),
            $this->legFlight('204', 'CDG', 'BEY', '2026-11-22 10:00', '2026-11-22 14:15'),
        ];
    }

    private function legFlight(string $no, string $from, string $to, string $depart, string $arrive): Flight
    {
        return $this->flightWithStatus(FlightStatus::Scheduled, [
            'flight_no' => $this->flightNo($no),
            'origin' => $from,
            'destination' => $to,
            'departure_at' => Carbon::parse($depart, 'UTC'),
            'arrives_at' => Carbon::parse($arrive, 'UTC'),
            'checkin_opens_at' => Carbon::parse($depart, 'UTC')->subHours(3),
            'checkin_closes_at' => Carbon::parse($depart, 'UTC')->subMinutes(45),
        ]);
    }

    /**
     * @param  list<list<int>>  $legs
     * @param  array<int, int>  $seats
     * @return array<string, mixed>
     */
    private function bookingPayload(array $legs, array $seats): array
    {
        return [
            'journey_type' => 'multi_city',
            'legs' => $legs,
            'passengers' => [[
                'first_name' => 'Rami',
                'last_name' => 'Haddad',
                'type' => 'Adult',
                'cabin_class' => 'Economy',
                'date_of_birth' => '1985-04-12',
                'seats' => $seats,
                'extra_bags' => 0,
                'extra_kg' => 0,
                'passport' => [
                    'number' => 'LR1234567',
                    'issuing_country' => 'LB',
                    'expiry_date' => '2030-01-01',
                ],
            ]],
            ...$this->paidBookingFields(),
        ];
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
