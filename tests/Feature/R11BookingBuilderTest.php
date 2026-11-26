<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\SegmentDirection;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class R11BookingBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
    }

    public function test_booking_form_offers_one_way_connection_and_round_trip(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('name="journey_type"', false)
            ->assertSee('value="one_way"', false)
            ->assertSee('value="connection"', false)
            ->assertSee('value="round_trip"', false)
            ->assertSee('With connection')
            ->assertSee('Round trip')
            ->assertSee('Multi-city')
            ->assertSee('Outbound')
            ->assertSee('connecting flights')
            ->assertSee('Same flight cannot be picked twice');
    }

    public function test_past_scheduled_flights_are_hidden_from_the_booking_list(): void
    {
        $this->actingAsRole('reservation');

        Flight::factory()->create([
            'flight_no' => $this->flightNo('900'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'status' => FlightStatus::Scheduled,
            'departure_at' => Carbon::parse('2026-11-01 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-01 12:00', 'UTC'),
        ]);
        $live = Flight::factory()->create([
            'flight_no' => $this->flightNo('901'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'status' => FlightStatus::Scheduled,
            'departure_at' => Carbon::parse('2026-11-20 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-20 12:00', 'UTC'),
        ]);

        $page = $this->get(route('reservation.create'));
        preg_match('/const allFlights = (\[.*?\]);/s', $page->getContent(), $match);

        $this->assertArrayHasKey(1, $match);
        $this->assertStringContainsString($this->flightNo('901'), $match[1]);
        $this->assertStringContainsString((string) $live->id, $match[1]);
        $this->assertStringNotContainsString($this->flightNo('900'), $match[1]);
    }

    public function test_SR65_one_way_booking_still_works_with_legacy_flight_id(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create([
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        $seatId = $this->economySeat($flight);

        $response = $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$this->passenger(['seat_id' => $seatId])],
            ...$this->paidBookingFields(),
        ]);

        $reservation = Reservation::query()->with('segments')->sole();
        $response->assertRedirect(route('reservation.confirmation', $reservation));
        $this->assertSame([$flight->id], $reservation->segments->pluck('flight_id')->all());
        $this->assertSame([SegmentDirection::Outbound], $reservation->segments->pluck('direction')->all());
    }

    public function test_SR65_connection_booking_creates_two_segments_and_seats_on_each_flight(): void
    {
        $this->actingAsRole('reservation');
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
                ]),
            ],
            ...$this->paidBookingFields(),
        ]);

        $reservation = Reservation::query()->with(['segments', 'passengers.coupons.seatAssignment.seat'])->sole();
        $response->assertRedirect(route('reservation.confirmation', $reservation));

        $this->assertSame([$first->id, $second->id], $reservation->segments->pluck('flight_id')->all());
        $this->assertSame($first->id, $reservation->flight_id);

        $passenger = $reservation->passengers->sole();
        $this->assertCount(2, $passenger->coupons);
        $this->assertSame($seatOut, $passenger->coupons[0]->seatAssignment->seat_id);
        $this->assertSame($seatIn, $passenger->coupons[1]->seatAssignment->seat_id);

        $page = $this->followRedirects($response);
        $page->assertOk()
            ->assertSee($this->flightNo('264'))
            ->assertSee($this->flightNo('216'))
            ->assertSee('BEY')
            ->assertSee('IST')
            ->assertSee('CDG');
    }

    public function test_SR65_connection_shorter_than_mct_is_refused(): void
    {
        $this->actingAsRole('reservation');
        [$first, $second] = $this->connection(gap: 30);
        $seatOut = $this->economySeat($first);
        $seatIn = $this->economySeat($second);

        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), [
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
            ])
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors('outbound_flight_ids');

        $this->assertSame(0, Reservation::count());
    }

    public function test_SR65_round_trip_booking_creates_outbound_and_return_segments(): void
    {
        $this->actingAsRole('reservation');
        [$out, $back] = $this->roundTrip();
        $seatOut = $this->economySeat($out);
        $seatBack = $this->economySeat($back);

        $response = $this->post(route('reservation.store'), [
            'journey_type' => 'round_trip',
            'outbound_flight_ids' => [$out->id],
            'return_flight_ids' => [$back->id],
            'passengers' => [
                $this->passenger([
                    'seats' => [
                        $out->id => $seatOut,
                        $back->id => $seatBack,
                    ],
                ]),
            ],
            ...$this->paidBookingFields(),
        ]);

        $reservation = Reservation::query()->with('segments')->sole();
        $response->assertRedirect(route('reservation.confirmation', $reservation));
        $this->assertSame([$out->id, $back->id], $reservation->segments->pluck('flight_id')->all());
        $this->assertSame(
            [SegmentDirection::Outbound, SegmentDirection::Return],
            $reservation->segments->pluck('direction')->all(),
        );
    }

    public function test_connection_requires_a_seat_on_each_flight(): void
    {
        $this->actingAsRole('reservation');
        [$first, $second] = $this->connection(gap: 90);
        $seatOut = $this->economySeat($first);

        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), [
                'journey_type' => 'connection',
                'outbound_flight_ids' => [$first->id, $second->id],
                'passengers' => [
                    $this->passenger([
                        'seats' => [
                            $first->id => $seatOut,
                        ],
                    ]),
                ],
                ...$this->paidBookingFields(),
            ])
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors("passengers.0.seats.{$second->id}");

        $this->assertSame(0, Reservation::count());
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
     * @return array{0: Flight, 1: Flight}
     */
    private function roundTrip(): array
    {
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
            'checkin_opens_at' => Carbon::parse('2026-11-18 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-18 09:15', 'UTC'),
        ]);

        return [$out, $back];
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
                'number' => 'LR9988776',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ], $overrides);
    }

    private function economySeat(Flight $flight): int
    {
        $row = 20 + Seat::query()->where('aircraft_id', $flight->aircraft_id)->count();

        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'cabin_class' => 'Economy',
            'seat_no' => $row.'A',
        ])->id;
    }
}
