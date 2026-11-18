<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Mail\ETicketMail;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\JourneyService;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R13 — e-ticket and email show every flight of the journey (local times, seats, connection, Outbound/Return).
 */
class R13EticketSegmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
    }

    public function test_SR65_connection_eticket_shows_both_flights_local_times_and_ist_connection(): void
    {
        [$reservation, $payment, $passenger] = $this->bookConnection();

        $this->actingAsRole('reservation');
        $page = $this->get(route('reservation.confirmation', $reservation));

        $page->assertOk()
            ->assertSee('Outbound')
            ->assertDontSee('>Return<', false)
            ->assertSee('ME264')
            ->assertSee('ME216')
            ->assertSee('BEY')
            ->assertSee('IST')
            ->assertSee('CDG')
            ->assertSee('Connection at IST')
            ->assertSee('12A')
            ->assertSee('14C')
            ->assertSee('••••4242');

        // Departure at origin local (BEY = UTC+2 in Nov): 08:00 UTC → 10:00
        $page->assertSee('10:00', false);
        // Arrival at IST local (UTC+3): 10:00 UTC → 13:00
        $page->assertSee('13:00', false);
        // 90 min connection
        $page->assertSee('1 h 30 min');

        $html = (new ETicketMail($reservation->fresh(), $payment))->render();

        $this->assertStringContainsString('ME264', $html);
        $this->assertStringContainsString('ME216', $html);
        $this->assertStringContainsString('Connection at IST', $html);
        $this->assertStringContainsString('1 h 30 min', $html);
        $this->assertStringContainsString('12A', $html);
        $this->assertStringContainsString('14C', $html);
        $this->assertStringContainsString($passenger->first_name, $html);
        $this->assertStringContainsString('••••4242', $html);
        $this->assertStringNotContainsString('4242 4242 4242 4242', $html);
    }

    public function test_round_trip_eticket_shows_outbound_and_return_sections(): void
    {
        [$reservation, $payment] = $this->bookRoundTrip();

        $this->actingAsRole('reservation');
        $this->get(route('reservation.confirmation', $reservation))
            ->assertOk()
            ->assertSee('Outbound')
            ->assertSee('Return')
            ->assertSee('ME201')
            ->assertSee('ME204')
            ->assertSeeInOrder(['Outbound', 'ME201', 'Return', 'ME204']);

        $html = (new ETicketMail($reservation->fresh(), $payment))->render();

        $this->assertStringContainsString('Outbound', $html);
        $this->assertStringContainsString('Return', $html);
        $this->assertStringContainsString('ME201', $html);
        $this->assertStringContainsString('ME204', $html);
    }

    public function test_payment_block_stays_on_receipt_with_last_four_only(): void
    {
        [$reservation] = $this->bookConnection();

        $this->actingAsRole('reservation');
        $this->get(route('reservation.confirmation', $reservation))
            ->assertOk()
            ->assertSee('data-receipt', false)
            ->assertSee('••••4242')
            ->assertDontSee('4242 4242 4242 4242');
    }

    /**
     * @return array{0: Reservation, 1: Payment, 2: Passenger}
     */
    private function bookConnection(): array
    {
        $first = Flight::factory()->create([
            'flight_no' => 'ME264',
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => 'ME216',
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:15', 'UTC'),
        ]);

        $reservation = Reservation::factory()->create(['flight_id' => $first->id]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));

        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'cabin_class' => CabinClass::Economy,
        ]);

        $this->seatCoupon($passenger->coupons()->where('flight_id', $first->id)->first(), '12A');
        $this->seatCoupon($passenger->coupons()->where('flight_id', $second->id)->first(), '14C');

        $payment = $this->charge($reservation);

        return [$reservation->fresh(), $payment, $passenger->fresh()];
    }

    /**
     * @return array{0: Reservation, 1: Payment}
     */
    private function bookRoundTrip(): array
    {
        $out = Flight::factory()->create([
            'flight_no' => 'ME201',
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:45', 'UTC'),
        ]);
        $back = Flight::factory()->create([
            'flight_no' => 'ME204',
            'origin' => 'CDG',
            'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-18 14:15', 'UTC'),
        ]);

        $reservation = Reservation::factory()->create(['flight_id' => $out->id]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$out], [$back]));

        $passenger = Passenger::factory()->for($reservation)->create(['cabin_class' => CabinClass::Economy]);
        foreach ($passenger->coupons as $coupon) {
            $this->seatCoupon($coupon, $coupon->flight_id === $out->id ? '10A' : '11B');
        }

        return [$reservation->fresh(), $this->charge($reservation)];
    }

    private function seatCoupon(Coupon $coupon, string $seatNo): void
    {
        $seat = Seat::factory()->create([
            'aircraft_id' => $coupon->flight->aircraft_id,
            'seat_no' => $seatNo,
            'cabin_class' => CabinClass::Economy,
        ]);
        SeatAssignment::create([
            'flight_id' => $coupon->flight_id,
            'seat_id' => $seat->id,
            'passenger_id' => $coupon->passenger_id,
            'coupon_id' => $coupon->id,
        ]);
    }

    private function charge(Reservation $reservation): Payment
    {
        $agent = User::factory()->create(['role' => Role::Reservation]);

        return app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
            25000,
            PaymentKind::Fare,
            $agent,
            reservation: $reservation,
        );
    }
}
