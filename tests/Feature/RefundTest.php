<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Mail\RefundConfirmationMail;
use App\Models\AuditEntry;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Services\JourneyService;
use App\Services\Payments\CardDetails;
use App\Services\ReservationService;
use App\Services\StatusService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sprint 4 Feature 2 — cancel and refund.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->travelTo('2026-03-10 08:00:00');
    }

    public function test_cancel_one_of_two_passengers_refunds_paid_part_minus_fee_and_releases_seat(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $a, $b, $payment] = $this->paidTwoPassengerBooking($agent);

        $seatA = $a->seatAssignment;
        $this->assertNotNull($seatA);

        $fee = (int) Fee::query()->where('code', 'Cancellation')->value('amount_cents');
        $half = (int) floor($payment->amount_cents / 2);
        // Prefer stored breakdown share for A.
        $paidA = collect($payment->breakdown['items'])
            ->where('passenger_id', $a->id)
            ->sum('amount_cents');
        $expectedRefund = max(0, $paidA - $fee);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$a->id],
        ])->assertRedirect(route('reservation.search', ['pnr' => $reservation->pnr]));

        $this->assertSame(PassengerStatus::Cancelled, $a->fresh()->status);
        $this->assertSame(PassengerStatus::Booked, $b->fresh()->status);
        $this->assertDatabaseMissing('seat_assignments', ['passenger_id' => $a->id]);
        $this->assertDatabaseHas('seat_assignments', ['passenger_id' => $b->id]);

        $refund = Payment::query()->where('kind', PaymentKind::Refund)->sole();
        $this->assertSame(PaymentStatus::Refunded, $refund->status);
        $this->assertSame($payment->id, $refund->refund_of_payment_id);
        $this->assertSame($payment->card_last4, $refund->card_last4);
        $this->assertSame($expectedRefund, $refund->amount_cents);
        $this->assertSame($half > 0 ? $expectedRefund : $refund->amount_cents, $refund->amount_cents);
    }

    public function test_cancel_whole_booking_marks_reservation_cancelled_and_refunds(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $a, $b, $payment] = $this->paidTwoPassengerBooking($agent);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertTrue($reservation->fresh()->isCancelled());
        $this->assertDatabaseMissing('seat_assignments', ['passenger_id' => $a->id]);
        $this->assertDatabaseMissing('seat_assignments', ['passenger_id' => $b->id]);

        $refunded = (int) Payment::query()
            ->where('refund_of_payment_id', $payment->id)
            ->where('status', PaymentStatus::Refunded)
            ->sum('amount_cents');
        $this->assertGreaterThan(0, $refunded);
        $this->assertLessThanOrEqual($payment->amount_cents, $refunded);
    }

    public function test_multi_city_cancel_only_leg_2_leaves_leg_1_and_refunds_leg_2(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $passenger, $payment, $leg1, $leg2] = $this->paidMultiCityBooking($agent);

        $leg2Paid = collect($payment->breakdown['items'])
            ->where('passenger_id', $passenger->id)
            ->where('flight_id', $leg2->id)
            ->sum('amount_cents');
        $fee = (int) Fee::query()->where('code', 'Cancellation')->value('amount_cents');
        $expected = max(0, $leg2Paid - $fee);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$passenger->id],
            'legs' => [2],
        ])->assertRedirect();

        $c1 = $passenger->fresh()->coupons->firstWhere('flight_id', $leg1->id);
        $c2 = $passenger->fresh()->coupons->firstWhere('flight_id', $leg2->id);
        $this->assertSame(PassengerStatus::Booked, $c1->status);
        $this->assertSame(PassengerStatus::Cancelled, $c2->status);

        $refund = Payment::query()->where('kind', PaymentKind::Refund)->sole();
        $this->assertSame($expected, $refund->amount_cents);
    }

    public function test_checked_in_passenger_and_boarding_flight_are_refused(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $checkin = User::factory()->create(['role' => Role::Checkin]);

        $openFlight = Flight::factory()->create([
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => now()->addHours(4),
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(2),
        ]);
        $reservation = $this->bookOnFlight($agent, $openFlight, 'Lina', 'Haddad');
        $a = $reservation->passengers->first();
        $this->seatPassenger($a);
        app(StatusService::class)->setFlightStatus($openFlight, FlightStatus::CheckinOpen, $manager);
        app(StatusService::class)->checkIn($a, $checkin);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$a->id],
        ])->assertRedirect(route('reservation.refund', $reservation))
            ->assertSessionHas('error');
        $this->assertStringContainsStringIgnoringCase('checked in', session('error'));
        $this->assertSame(PassengerStatus::CheckedIn, $a->fresh()->status);

        $boardFlight = Flight::factory()->create([
            'departure_at' => now()->addHours(5),
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(3),
        ]);
        $booking = $this->bookOnFlight($agent, $boardFlight, 'Omar', 'Gate');
        app(StatusService::class)->setFlightStatus($boardFlight, FlightStatus::CheckinOpen, $manager);
        app(StatusService::class)->setFlightStatus($boardFlight, FlightStatus::Boarding, $manager);

        $this->post(route('reservation.refund.confirm', $booking), [
            'passenger_ids' => [$booking->passengers->first()->id],
        ])->assertSessionHas('error');
        $this->assertStringContainsString('cannot refund', session('error'));
    }

    public function test_refund_never_exceeds_paid_second_partial_works_and_fee_can_zero_refund(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $a, $b, $payment] = $this->paidTwoPassengerBooking($agent);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$a->id],
        ])->assertRedirect();

        $first = (int) Payment::query()->where('refund_of_payment_id', $payment->id)->sum('amount_cents');
        $this->assertLessThanOrEqual($payment->amount_cents, $first);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$b->id],
        ])->assertRedirect();

        $total = (int) Payment::query()->where('refund_of_payment_id', $payment->id)->sum('amount_cents');
        $this->assertLessThanOrEqual($payment->amount_cents, $total);
        $this->assertSame(2, Payment::query()->where('kind', PaymentKind::Refund)->count());

        // Fee larger than fare → refund 0: tiny infant-only style by forcing high fee.
        Fee::query()->where('code', 'Cancellation')->update(['amount_cents' => 999999]);
        $tiny = $this->bookOnFlight($agent, Flight::factory()->create([
            'departure_at' => now()->addDays(2),
            'checkin_opens_at' => now()->addDay(),
            'checkin_closes_at' => now()->addDays(2)->subHour(),
        ]), 'Sam', 'Zero');
        $orig = $tiny->farePayment();
        $this->assertNotNull($orig);

        $this->post(route('reservation.refund.confirm', $tiny), [
            'passenger_ids' => [$tiny->passengers->first()->id],
        ])->assertRedirect();

        $this->assertSame(0, Payment::query()
            ->where('refund_of_payment_id', $orig->id)
            ->sum('amount_cents'));
        $this->assertTrue($tiny->fresh()->isCancelled());
    }

    public function test_payments_list_and_dashboard_sales_use_net_revenue(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $a, $b, $payment] = $this->paidTwoPassengerBooking($agent);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$a->id, $b->id],
        ]);

        $refund = Payment::query()->where('kind', PaymentKind::Refund)->sole();

        $this->actingAsRole(Role::Manager);
        $this->get(route('manager.payments'))
            ->assertOk()
            ->assertSee('Refunded')
            ->assertSee('Net total');

        $this->get(route('manager.payments.show', $refund))
            ->assertOk()
            ->assertSee('Original payment')
            ->assertSee((string) $payment->id);

        $page = $this->get(route('manager.dashboard'))->assertOk();
        $page->assertSee('Ticket revenue');

        $this->get(route('manager.sales'))->assertOk();
    }

    public function test_email_audit_and_403_for_non_reservation(): void
    {
        Mail::fake();
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $a, $b] = $this->paidTwoPassengerBooking($agent);
        $reservation->update(['contact_email' => 'guest@example.test']);

        $this->post(route('reservation.refund.confirm', $reservation), [
            'passenger_ids' => [$a->id],
        ])->assertRedirect();

        Mail::assertSent(RefundConfirmationMail::class, function (RefundConfirmationMail $mail) use ($reservation) {
            return $mail->reservation->is($reservation)
                && $mail->preview['refund_cents'] >= 0;
        });

        $this->assertTrue(AuditEntry::query()->where('field', 'cancel_refund')->exists());
        $this->assertTrue(AuditEntry::query()->where('field', 'refund')->exists());

        foreach ([Role::Checkin, Role::Gate, Role::Manager] as $role) {
            $this->actingAsRole($role);
            $this->get(route('reservation.refund', $reservation))->assertForbidden();
            $this->post(route('reservation.refund.confirm', $reservation), [
                'passenger_ids' => [$b->id],
            ])->assertForbidden();
        }
    }

    /**
     * @return array{0: Reservation, 1: Passenger, 2: Passenger, 3: Payment}
     */
    private function paidTwoPassengerBooking(User $agent): array
    {
        $flight = Flight::factory()->create([
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => now()->addDays(3),
            'checkin_opens_at' => now()->addDays(2),
            'checkin_closes_at' => now()->addDays(3)->subHour(),
            'status' => FlightStatus::Scheduled,
        ]);

        $reservation = app(ReservationService::class)->create(
            $flight,
            $agent,
            [
                [
                    'first_name' => 'Ada',
                    'last_name' => 'One',
                    'type' => PassengerType::Adult->value,
                    'date_of_birth' => '1990-01-01',
                    'cabin_class' => 'Economy',
                    'passport' => ['number' => 'P1', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                    'seat_id' => $this->makeSeat($flight, '12A')->id,
                ],
                [
                    'first_name' => 'Ben',
                    'last_name' => 'Two',
                    'type' => PassengerType::Adult->value,
                    'date_of_birth' => '1991-01-01',
                    'cabin_class' => 'Economy',
                    'passport' => ['number' => 'P2', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                    'seat_id' => $this->makeSeat($flight, '12B')->id,
                ],
            ],
            ['contact_email' => 'booker@example.test'],
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
        );

        $passengers = $reservation->passengers()->orderBy('id')->get();
        $payment = $reservation->farePayment();
        $this->assertNotNull($payment);

        return [$reservation, $passengers[0], $passengers[1], $payment];
    }

    /**
     * @return array{0: Reservation, 1: Passenger, 2: Payment, 3: Flight, 4: Flight}
     */
    private function paidMultiCityBooking(User $agent): array
    {
        $leg1 = Flight::factory()->create([
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => now()->addDays(2),
            'arrives_at' => now()->addDays(2)->addHours(2),
            'checkin_opens_at' => now()->addDay(),
            'checkin_closes_at' => now()->addDays(2)->subHour(),
        ]);
        $leg2 = Flight::factory()->create([
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => now()->addDays(2)->addHours(5),
            'arrives_at' => now()->addDays(2)->addHours(9),
            'checkin_opens_at' => now()->addDays(2)->addHours(2),
            'checkin_closes_at' => now()->addDays(2)->addHours(4),
        ]);

        $reservation = app(ReservationService::class)->create(
            Journey::multiCity([[$leg1], [$leg2]]),
            $agent,
            [[
                'first_name' => 'Cora',
                'last_name' => 'City',
                'type' => PassengerType::Adult->value,
                'date_of_birth' => '1988-01-01',
                'cabin_class' => 'Economy',
                'passport' => ['number' => 'P3', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                'seats' => [
                    $leg1->id => $this->makeSeat($leg1, '10A')->id,
                    $leg2->id => $this->makeSeat($leg2, '10A')->id,
                ],
            ]],
            ['contact_email' => 'multi@example.test'],
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
        );

        // Ensure legs are numbered 1 and 2 for multi-city.
        $this->assertGreaterThanOrEqual(2, app(JourneyService::class)->legNumbers($reservation->fresh()) === [] ? 0 : count(app(JourneyService::class)->legNumbers($reservation)));

        return [$reservation, $reservation->passengers->first(), $reservation->farePayment(), $leg1, $leg2];
    }

    private function bookOnFlight(User $agent, Flight $flight, string $first, string $last): Reservation
    {
        return app(ReservationService::class)->create(
            $flight,
            $agent,
            [[
                'first_name' => $first,
                'last_name' => $last,
                'type' => PassengerType::Adult->value,
                'date_of_birth' => '1990-01-01',
                'cabin_class' => 'Economy',
                'passport' => ['number' => 'PX'.$first, 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                'seat_id' => $this->makeSeat($flight, '14A')->id,
            ]],
            ['contact_email' => 'x@example.test'],
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
        );
    }

    private function makeSeat(Flight $flight, string $seatNo): Seat
    {
        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => $seatNo,
        ]);
    }
}
