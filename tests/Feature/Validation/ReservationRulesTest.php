<?php

namespace Tests\Feature\Validation;

use App\Enums\FlightStatus;
use App\Models\Flight;
use App\Models\Reservation;
use App\Support\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->seedBookingPrices();
        $this->actingAsRole('reservation');
    }

    public function test_passenger_type_must_match_age_on_travel_date(): void
    {
        $flight = Flight::factory()->create(['departure_at' => now()->addDays(2)]);
        $seat = $this->seatsFor($flight, 1)[0];

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->pax('Rami', 'Haddad', 'Adult', '2020-01-01', $seat),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('passengers.0.type');

        $adultSeat = $this->seatsFor($flight, 1)[0];
        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->pax('Maya', 'Haddad', 'Adult', '1985-04-12', $adultSeat),
                $this->pax('Rami', 'Haddad', 'Child', '2020-01-01', $seat),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
    }

    public function test_max_one_infant_per_adult_and_under_16_needs_adult(): void
    {
        $flight = Flight::factory()->create();
        $seats = $this->seatsFor($flight, 2);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->pax('Nour', 'Haddad', 'Child', '2016-06-21', $seats[0]),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('passengers.0.type');

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seats[0]),
                $this->infant('Lea', 'Haddad', '2025-06-01', 0),
                $this->infant('Omar', 'Haddad', '2025-08-01', 0),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('passengers.0.type');

        $this->assertSame(0, Reservation::count());

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seats[0]),
                $this->pax('Maya', 'Haddad', 'Adult', '1988-09-03', $seats[1]),
                $this->infant('Lea', 'Haddad', '2025-06-01', 0),
                $this->infant('Omar', 'Haddad', '2025-08-01', 1),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, Limits::maxInfantsPerAdult());
    }

    public function test_passport_must_not_be_expired_on_travel_date(): void
    {
        $flight = Flight::factory()->create(['departure_at' => now()->addDays(5)]);
        $seat = $this->seatsFor($flight, 1)[0];

        $bad = $this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seat);
        $bad['passport']['expiry_date'] = now()->toDateString();

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$bad],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('passengers.0.passport.expiry_date');

        $ok = $this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seat);
        $ok['passport']['expiry_date'] = now()->addDays(5)->toDateString();

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$ok],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
    }

    public function test_luhn_and_booking_token_block_double_submit(): void
    {
        $flight = Flight::factory()->create();
        $seat = $this->seatsFor($flight, 1)[0];
        $token = (string) Str::uuid();

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seat)],
            ...$this->paidBookingFields([
                'card_number' => '4242 4242 4242 4241',
                'booking_token' => $token,
            ]),
        ])->assertSessionHasErrors('card_number');

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seat)],
            ...$this->paidBookingFields(['booking_token' => $token]),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
        $reservation = Reservation::first();

        $seat2 = $this->seatsFor($flight, 1)[0];
        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$this->pax('Maya', 'Haddad', 'Adult', '1988-09-03', $seat2)],
            ...$this->paidBookingFields(['booking_token' => $token]),
        ])->assertRedirect(route('reservation.confirmation', $reservation));

        $this->assertSame(1, Reservation::count());
    }

    public function test_boarding_flight_cannot_be_booked(): void
    {
        $boarding = $this->flightWithStatus(FlightStatus::Boarding);
        $seat = $this->seatsFor($boarding, 1)[0];

        $this->post(route('reservation.store'), [
            'flight_id' => $boarding->id,
            'passengers' => [$this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $seat)],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors('outbound_flight_ids.0');

        $open = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $okSeat = $this->seatsFor($open, 1)[0];

        $this->post(route('reservation.store'), [
            'flight_id' => $open->id,
            'passengers' => [$this->pax('Rami', 'Haddad', 'Adult', '1985-04-12', $okSeat)],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
    }

    public function test_group_badge_on_confirmation_for_ten_or_more(): void
    {
        $flight = Flight::factory()->create();
        $seatIds = $this->seatsFor($flight, 10);

        $passengers = [];
        foreach ($seatIds as $i => $seatId) {
            $passengers[] = $this->pax("Pax{$i}", 'Group', 'Adult', '1985-04-12', $seatId);
        }

        $response = $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => $passengers,
            ...$this->paidBookingFields(),
        ]);

        $response->assertRedirect();
        $reservation = Reservation::first();
        $this->assertTrue($reservation->isGroup());

        $this->get(route('reservation.confirmation', $reservation))
            ->assertOk()
            ->assertSee('Group');
    }

    /**
     * @return array<string, mixed>
     */
    private function pax(string $first, string $last, string $type, string $dob, int $seatId): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'type' => $type,
            'cabin_class' => $type === 'Infant' ? null : 'Economy',
            'date_of_birth' => $dob,
            'seat_id' => $seatId,
            'passport' => [
                'number' => 'LR'.str_pad((string) abs(crc32($first.$last.$dob)), 7, '0', STR_PAD_LEFT),
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function infant(string $first, string $last, string $dob, int $adultIndex): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'type' => 'Infant',
            'cabin_class' => null,
            'date_of_birth' => $dob,
            'accompanying_adult' => $adultIndex,
            'passport' => [
                'number' => 'LR'.str_pad((string) abs(crc32($first.$last.$dob)), 7, '0', STR_PAD_LEFT),
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];
    }
}
