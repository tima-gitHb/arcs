<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R1CreateReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_SR1_one_hundred_reservations_produce_different_pnrs_shown_after_creation(): void
    {
        $agent = $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatIds = $this->seatsFor($flight, 100);
        $pnrs = [];

        for ($i = 0; $i < 100; $i++) {
            $response = $this->post(route('reservation.store'), [
                'flight_id' => $flight->id,
                'passengers' => [
                    $this->passenger('Rami', 'Haddad'.$i, 'Adult', '1990-01-15', seatId: $seatIds[$i]),
                ],
                ...$this->paidBookingFields(),
            ]);

            $response->assertRedirect();

            $reservation = Reservation::query()->latest('id')->first();
            $this->assertNotNull($reservation);
            $this->assertSame($agent->id, $reservation->created_by);
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $reservation->pnr);

            $this->followRedirects($response)
                ->assertOk()
                ->assertSee($reservation->pnr);

            $pnrs[] = $reservation->pnr;
        }

        $this->assertCount(100, array_unique($pnrs));
        $this->assertSame(100, Reservation::count());
    }

    public function test_SR4_three_passengers_share_one_pnr_and_are_booked(): void
    {
        $agent = $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatIds = $this->seatsFor($flight, 3);

        $response = $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $seatIds[0]),
                $this->passenger('Maya', 'Haddad', 'Adult', '1988-09-03', seatId: $seatIds[1]),
                $this->passenger('Nour', 'Haddad', 'Child', '2016-06-21', seatId: $seatIds[2]),
            ],
            ...$this->paidBookingFields(),
        ]);

        $response->assertRedirect();

        $this->assertSame(1, Reservation::count());

        $reservation = Reservation::query()->with('passengers')->first();
        $this->assertSame($agent->id, $reservation->created_by);
        $this->assertCount(3, $reservation->passengers);
        $this->assertSame(
            1,
            $reservation->passengers->pluck('reservation_id')->unique()->count(),
        );

        foreach ($reservation->passengers as $passenger) {
            $this->assertSame(PassengerStatus::Booked, $passenger->status);
        }

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee($reservation->pnr)
            ->assertSee('Rami')
            ->assertSee('Maya')
            ->assertSee('Nour')
            ->assertSee('e-ticket');
    }

    public function test_SR24_infant_is_linked_to_adult_without_a_seat_and_infant_alone_is_refused(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatIds = $this->seatsFor($flight, 1);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $seatIds[0]),
                $this->passenger('Lea', 'Haddad', 'Infant', '2025-06-01', adultIndex: 0),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $reservation = Reservation::query()->with('passengers')->first();
        $adult = $reservation->passengers->firstWhere('type', PassengerType::Adult);
        $infant = $reservation->passengers->firstWhere('type', PassengerType::Infant);

        $this->assertNotNull($adult);
        $this->assertNotNull($infant);
        $this->assertSame($adult->id, $infant->accompanying_adult_id);
        $this->assertTrue($infant->accompanyingAdult->is($adult));
        $this->assertSame($seatIds[0], $adult->seatAssignment?->seat_id);
        $this->assertNull($infant->seatAssignment);
        $this->assertSame(0, SeatAssignment::query()->where('passenger_id', $infant->id)->count());

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger('Lea', 'Haddad', 'Infant', '2025-06-01'),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('passengers.0.accompanying_adult');

        $this->assertSame(1, Reservation::count());
        $this->assertSame(2, $reservation->passengers()->count());
    }

    public function test_checkin_open_and_boarding_flights_can_be_reserved(): void
    {
        $this->actingAsRole('reservation');

        $closed = $this->flightWithStatus(FlightStatus::Closed, ['flight_no' => 'ME701']);
        $checkin = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME702']);
        $boarding = $this->flightWithStatus(FlightStatus::Boarding, ['flight_no' => 'ME703']);
        $departed = $this->flightWithStatus(FlightStatus::Departed, ['flight_no' => 'ME704']);
        $cancelled = $this->flightWithStatus(FlightStatus::Cancelled, ['flight_no' => 'ME705']);

        $page = $this->get(route('reservation.create'));

        $page->assertOk()
            ->assertSee('ME702')
            ->assertSee('ME703')
            ->assertSee('Check-in open')
            ->assertSee('Boarding');

        preg_match('/const allFlights = (\[.*?\]);/s', $page->getContent(), $bookingList);

        $this->assertArrayHasKey(1, $bookingList);
        $this->assertStringContainsString('ME702', $bookingList[1]);
        $this->assertStringContainsString('ME703', $bookingList[1]);
        $this->assertStringNotContainsString('ME701', $bookingList[1]);
        $this->assertStringNotContainsString('ME704', $bookingList[1]);
        $this->assertStringNotContainsString('ME705', $bookingList[1]);

        foreach ([$checkin, $boarding] as $flight) {
            $seatIds = $this->seatsFor($flight, 1);

            $this->post(route('reservation.store'), [
                'flight_id' => $flight->id,
                'passengers' => [
                    $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $seatIds[0]),
                ],
                ...$this->paidBookingFields(),
            ])->assertRedirect();

            $this->assertTrue(
                Reservation::query()->where('flight_id', $flight->id)->exists(),
            );
        }

        $closedSeat = $this->seatsFor($closed, 1)[0];
        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), [
                'flight_id' => $closed->id,
                'passengers' => [
                    $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $closedSeat),
                ],
                ...$this->paidBookingFields(),
            ])
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors('outbound_flight_ids.0');

        $this->post(route('reservation.store'), [
            'flight_id' => $departed->id,
            'passengers' => [
                $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12'),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('outbound_flight_ids.0');

        $this->assertSame(2, Reservation::count());
        $this->assertFalse(Reservation::query()->where('flight_id', $closed->id)->exists());
        $this->assertFalse(Reservation::query()->where('flight_id', $cancelled->id)->exists());
    }

    /**
     * @return array<string, mixed>
     */
    private function passenger(string $first, string $last, string $type, string $dob, ?int $adultIndex = null, ?int $seatId = null): array
    {
        $row = [
            'first_name' => $first,
            'last_name' => $last,
            'type' => $type,
            'cabin_class' => $type === 'Infant' ? null : 'Economy',
            'date_of_birth' => $dob,
            'passport' => [
                'number' => 'LR'.str_pad((string) abs(crc32($first.$last.$type.$dob)), 7, '0', STR_PAD_LEFT),
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];

        if ($adultIndex !== null) {
            $row['accompanying_adult'] = $adultIndex;
        }

        if ($seatId !== null) {
            $row['seat_id'] = $seatId;
        }

        return $row;
    }

    /**
     * @return list<int>
     */
    private function seatsFor(Flight $flight, int $count): array
    {
        $now = now();
        $rows = [];

        for ($number = 1; $number <= $count; $number++) {
            $rows[] = [
                'aircraft_id' => $flight->aircraft_id,
                'seat_no' => $number.'A',
                'cabin_class' => 'Economy',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Seat::query()->insert($rows);

        return Seat::query()
            ->where('aircraft_id', $flight->aircraft_id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
