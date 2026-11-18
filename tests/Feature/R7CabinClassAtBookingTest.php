<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R7CabinClassAtBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_booking_form_lets_staff_choose_a_cabin_class(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('Cabin class')
            ->assertSee('Business')
            ->assertSee('Premium')
            ->assertSee('Economy');
    }

    public function test_the_booked_cabin_class_is_saved_and_the_seat_must_match_it(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();

        $business = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '1A',
            'cabin_class' => CabinClass::Business,
        ]);
        $economy = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12A',
            'cabin_class' => CabinClass::Economy,
        ]);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($business->id, CabinClass::Business),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $passenger = Passenger::query()->sole();
        $this->assertSame(CabinClass::Business, $passenger->cabin_class);
        $this->assertSame($business->id, $passenger->seatAssignment->seat_id);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($economy->id, CabinClass::Business, 'Maya'),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors([
            'passengers.0.seat_id' => 'Choose a seat in that cabin class.',
        ]);

        $this->assertSame(1, Reservation::count());
    }

    public function test_an_infant_takes_the_adults_cabin_class_and_no_seat(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '2A',
            'cabin_class' => CabinClass::Premium,
        ]);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seat->id, CabinClass::Premium, 'Rami'),
                [
                    'first_name' => 'Lea',
                    'last_name' => 'Haddad',
                    'type' => 'Infant',
                    'date_of_birth' => '2025-06-01',
                    'accompanying_adult' => 0,
                    'passport' => [
                        'number' => 'LR2222222',
                        'issuing_country' => 'LB',
                        'expiry_date' => '2031-01-01',
                    ],
                ],
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $infant = Passenger::query()->where('type', 'Infant')->sole();
        $this->assertSame(CabinClass::Premium, $infant->cabin_class);
        $this->assertNull($infant->seatAssignment);
    }

    /**
     * @return array<string, mixed>
     */
    private function passenger(int $seatId, CabinClass $cabin, string $first = 'Rami'): array
    {
        return [
            'first_name' => $first,
            'last_name' => 'Haddad',
            'type' => 'Adult',
            'date_of_birth' => '1985-04-12',
            'cabin_class' => $cabin->value,
            'seat_id' => $seatId,
            'passport' => [
                'number' => 'LR'.str_pad((string) abs(crc32($first.$cabin->value.$seatId)), 7, '0', STR_PAD_LEFT),
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];
    }
}
