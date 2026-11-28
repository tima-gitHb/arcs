<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Mail\ETicketMail;
use App\Models\Aircraft;
use App\Models\AuditEntry;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\CheckinFlightService;
use App\Services\ManifestService;
use App\Services\Payments\CardDetails;
use App\Services\ReservationService;
use App\Services\StatusService;
use App\Support\Journey;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sprint 4 Feature 3 — ticket exchanges.
 */
class ExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->travelTo('2026-03-10 08:00:00');
    }

    public function test_SR_exchange_higher_fare_charges_difference_plus_fee_and_sends_eticket(): void
    {
        Mail::fake();
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $passenger, $payment, $leg1, $leg2] = $this->paidMultiCityBooking($agent);
        $reservation->update(['contact_email' => 'guest@example.test']);
        $pnr = $reservation->pnr;

        $oldSeat = SeatAssignment::query()
            ->where('passenger_id', $passenger->id)
            ->where('flight_id', $leg2->id)
            ->first();
        $this->assertNotNull($oldSeat);
        $oldSeatId = $oldSeat->seat_id;

        $paidLeg2 = (int) collect($payment->breakdown['items'])
            ->where('passenger_id', $passenger->id)
            ->where('flight_id', $leg2->id)
            ->sum('amount_cents');

        Fare::query()
            ->whereNull('origin_airport_code')
            ->where('cabin_class', 'Economy')
            ->where('passenger_type', 'Adult')
            ->update(['amount_cents' => 40000]);

        $newLeg2 = $this->laterSameRoute($leg2, days: 3);
        $this->makeSeat($newLeg2, '20A');

        $fee = (int) Fee::query()->where('code', 'Change')->value('amount_cents');
        $expectedCharge = (40000 - $paidLeg2) + $fee;
        $this->assertGreaterThan($fee, $expectedCharge);

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$newLeg2->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect(route('reservation.search', ['pnr' => $pnr]))
            ->assertSessionHas('exchange_receipt');

        $oldCoupon = $passenger->fresh()->coupons()->where('flight_id', $leg2->id)->first();
        $newCoupon = $passenger->fresh()->coupons()->where('flight_id', $newLeg2->id)->first();
        $this->assertSame(PassengerStatus::Exchanged, $oldCoupon->status);
        $this->assertSame(PassengerStatus::Booked, $newCoupon->status);
        $this->assertSame($pnr, $reservation->fresh()->pnr);

        $this->assertDatabaseMissing('seat_assignments', [
            'passenger_id' => $passenger->id,
            'flight_id' => $leg2->id,
        ]);
        $this->assertDatabaseMissing('seat_assignments', [
            'flight_id' => $leg2->id,
            'seat_id' => $oldSeatId,
        ]);
        $this->assertDatabaseHas('seat_assignments', [
            'passenger_id' => $passenger->id,
            'flight_id' => $newLeg2->id,
        ]);

        $charge = Payment::query()->where('kind', PaymentKind::Exchange)->sole();
        $this->assertSame(PaymentStatus::Succeeded, $charge->status);
        $this->assertSame($expectedCharge, $charge->amount_cents);
        $this->assertSame($reservation->id, $charge->reservation_id);

        Mail::assertSent(ETicketMail::class, function (ETicketMail $mail) use ($reservation) {
            return $mail->reservation->is($reservation)
                && is_string($mail->changeNote)
                && str_contains($mail->changeNote, 'Changed on')
                && str_contains($mail->changeNote, '→');
        });
    }

    public function test_SR_exchange_lower_fare_charges_fee_only_and_shows_not_refunded_note(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $passenger, , , $leg2] = $this->paidMultiCityBooking($agent);

        Fare::query()
            ->whereNull('origin_airport_code')
            ->where('cabin_class', 'Economy')
            ->where('passenger_type', 'Adult')
            ->update(['amount_cents' => 5000]);

        $newLeg2 = $this->laterSameRoute($leg2, days: 2);
        $this->makeSeat($newLeg2, '21A');
        $fee = (int) Fee::query()->where('code', 'Change')->value('amount_cents');

        $this->post(route('reservation.exchange.review', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$newLeg2->id],
        ])->assertOk()
            ->assertSee('The fare difference is not refunded')
            ->assertSee(Money::format($fee));

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$newLeg2->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect()
            ->assertSessionHas('exchange_receipt', fn ($r) => ($r['difference_not_refunded'] ?? false) === true);

        $charge = Payment::query()->where('kind', PaymentKind::Exchange)->sole();
        $this->assertSame($fee, $charge->amount_cents);
        $this->assertSame(0, Payment::query()->where('kind', PaymentKind::Refund)->count());
        $this->assertSame(PassengerStatus::Exchanged, $passenger->fresh()->coupons()->where('flight_id', $leg2->id)->first()->status);
    }

    public function test_SR_exchange_refuses_checked_in_full_cabin_and_boarding_new_flight(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);

        $openFlight = Flight::factory()->create([
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => now()->addHours(4),
            'arrives_at' => now()->addHours(8),
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(2),
            'status' => FlightStatus::Scheduled,
        ]);
        $reservation = $this->bookOnFlight($agent, $openFlight, 'Lina', 'Haddad');
        $passenger = $reservation->passengers->first();
        app(StatusService::class)->setFlightStatus($openFlight, FlightStatus::CheckinOpen, $manager);
        app(StatusService::class)->checkIn($passenger->fresh(), $checkin);

        $later = Flight::factory()->create([
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => now()->addDays(2),
            'arrives_at' => now()->addDays(2)->addHours(4),
            'checkin_opens_at' => now()->addDay(),
            'checkin_closes_at' => now()->addDays(2)->subHour(),
            'status' => FlightStatus::Scheduled,
        ]);
        $this->makeSeat($later, '22A');

        $this->actingAsRole(Role::Reservation);
        $this->post(route('reservation.exchange.confirm', [$reservation, 1]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$later->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect()
            ->assertSessionHas('error');
        $this->assertStringContainsStringIgnoringCase('checked in', session('error'));

        // New flight Boarding → refused.
        [$booking, $pax, , , $legB] = $this->paidMultiCityBooking($agent);
        $boarding = $this->laterSameRoute($legB, days: 5);
        $this->makeSeat($boarding, '23A');
        app(StatusService::class)->setFlightStatus($boarding, FlightStatus::CheckinOpen, $manager);
        app(StatusService::class)->setFlightStatus($boarding->fresh(), FlightStatus::Boarding, $manager);

        $this->actingAsRole(Role::Reservation);
        $this->post(route('reservation.exchange.confirm', [$booking, 2]), [
            'passenger_ids' => [$pax->id],
            'flight_ids' => [$boarding->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertSessionHas('error');
        $this->assertStringContainsStringIgnoringCase('cannot be booked', session('error'));

        // Cabin full on new flight (aircraft with no Economy seats left / none at all).
        [$booking2, $pax2, , , $legC] = $this->paidMultiCityBooking($agent);
        $full = Flight::factory()->create([
            'flight_no' => $legC->flight_no,
            'origin' => $legC->origin,
            'destination' => $legC->destination,
            'departure_at' => $legC->departure_at->copy()->addDays(6),
            'arrives_at' => $legC->arrives_at->copy()->addDays(6),
            'checkin_opens_at' => $legC->checkin_opens_at->copy()->addDays(6),
            'checkin_closes_at' => $legC->checkin_closes_at->copy()->addDays(6),
            'status' => FlightStatus::Scheduled,
            'aircraft_id' => Aircraft::factory(),
        ]);
        // No seats on this aircraft → zero free Economy seats.

        $this->post(route('reservation.exchange.confirm', [$booking2, 2]), [
            'passenger_ids' => [$pax2->id],
            'flight_ids' => [$full->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertSessionHas('error');
        $this->assertStringContainsStringIgnoringCase('no free', session('error'));
    }

    public function test_SR_exchange_refuses_when_new_leg_departs_before_previous_lands(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $passenger, , $leg1, $leg2] = $this->paidMultiCityBooking($agent);

        $tooEarly = Flight::factory()->create([
            'origin' => $leg2->origin,
            'destination' => $leg2->destination,
            'departure_at' => $leg1->arrives_at->copy()->subHour(),
            'arrives_at' => $leg1->arrives_at->copy()->addHour(),
            'checkin_opens_at' => now()->addDay(),
            'checkin_closes_at' => $leg1->arrives_at->copy()->subHours(2),
            'status' => FlightStatus::Scheduled,
        ]);
        $this->makeSeat($tooEarly, '25A');

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$tooEarly->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertSessionHas('error');
        $this->assertStringContainsStringIgnoringCase('previous leg', session('error'));
    }

    public function test_SR_exchange_only_selected_passengers_move(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $a, $b, , $leg2] = $this->paidTwoPassengerMultiCity($agent);

        $newLeg2 = $this->laterSameRoute($leg2, days: 3);
        $this->makeSeat($newLeg2, '26A');
        $this->makeSeat($newLeg2, '26B');

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$a->id],
            'flight_ids' => [$newLeg2->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect();

        $this->assertSame(PassengerStatus::Exchanged, $a->fresh()->coupons()->where('flight_id', $leg2->id)->first()->status);
        $this->assertSame(PassengerStatus::Booked, $a->fresh()->coupons()->where('flight_id', $newLeg2->id)->first()->status);
        $this->assertSame(PassengerStatus::Booked, $b->fresh()->coupons()->where('flight_id', $leg2->id)->first()->status);
        $this->assertNull($b->fresh()->coupons()->where('flight_id', $newLeg2->id)->first());
    }

    public function test_SR_exchange_exchanged_hidden_from_checkin_and_manifest_new_flight_works(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        $manager = User::factory()->create(['role' => Role::Manager]);
        [$reservation, $passenger, , $leg1, $leg2] = $this->paidMultiCityBooking($agent);

        $newLeg2 = $this->laterSameRoute($leg2, days: 3);
        $this->makeSeat($newLeg2, '27A');

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$newLeg2->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect();

        $onOld = app(CheckinFlightService::class)->reservations($leg2);
        $this->assertFalse($onOld->contains(fn ($row) => $row['reservation']->id === $reservation->id));

        $manifestOld = app(ManifestService::class)->passengers($leg2);
        $this->assertFalse($manifestOld->contains(fn ($p) => $p->id === $passenger->id));

        $onNew = app(CheckinFlightService::class)->reservations($newLeg2->fresh());
        $this->assertTrue($onNew->contains(fn ($row) => $row['reservation']->id === $reservation->id));

        // Finish leg 1 so leg 2 check-in is allowed, then check in on the new flight.
        $checkinBey = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        $gateBey = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'BEY']);
        $leg1->update([
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(2),
            'departure_at' => now()->addHours(3),
            'arrives_at' => now()->addHours(5),
        ]);
        app(StatusService::class)->setFlightStatus($leg1->fresh(), FlightStatus::CheckinOpen, $manager);
        app(StatusService::class)->checkIn($passenger->fresh(), $checkinBey, leg: 1);
        app(StatusService::class)->setFlightStatus($leg1->fresh(), FlightStatus::Boarding, $manager);
        app(StatusService::class)->board($passenger->fresh(), $gateBey, $leg1->fresh());

        $newLeg2->update([
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(4),
            'departure_at' => now()->addHours(8),
            'arrives_at' => now()->addHours(12),
        ]);
        $checkinIst = User::factory()->create(['role' => Role::Checkin, 'airport_code' => $newLeg2->origin]);
        app(StatusService::class)->setFlightStatus($newLeg2->fresh(), FlightStatus::CheckinOpen, $manager);
        app(StatusService::class)->checkIn($passenger->fresh(), $checkinIst, leg: 2);

        $this->assertSame(
            PassengerStatus::CheckedIn,
            $passenger->fresh()->coupons()->where('flight_id', $newLeg2->id)->first()->status,
        );
    }

    public function test_SR_exchange_works_without_payment_breakdown_fallback(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $passenger, $payment, , $leg2] = $this->paidMultiCityBooking($agent);
        $payment->forceFill(['breakdown' => null])->save();

        $newLeg2 = $this->laterSameRoute($leg2, days: 2);
        $this->makeSeat($newLeg2, '28A');

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$newLeg2->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect(route('reservation.search', ['pnr' => $reservation->pnr]));

        $this->assertSame(PassengerStatus::Exchanged, $passenger->fresh()->coupons()->where('flight_id', $leg2->id)->first()->status);
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->coupons()->where('flight_id', $newLeg2->id)->first()->status);
        $this->assertTrue(Payment::query()->where('kind', PaymentKind::Exchange)->exists());
    }

    public function test_SR_exchange_writes_audit_and_forbids_other_roles(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
        [$reservation, $passenger, , , $leg2] = $this->paidMultiCityBooking($agent);
        $newLeg2 = $this->laterSameRoute($leg2, days: 2);
        $this->makeSeat($newLeg2, '29A');

        $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
            'passenger_ids' => [$passenger->id],
            'flight_ids' => [$newLeg2->id],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ])->assertRedirect();

        $this->assertTrue(AuditEntry::query()->where('field', 'exchange')->exists());
        $this->assertTrue(AuditEntry::query()->where('field', 'status')->where('new_value', 'Exchanged')->exists());

        foreach ([Role::Checkin, Role::Gate, Role::Manager] as $role) {
            $this->actingAsRole($role);
            $this->get(route('reservation.exchange', [$reservation, 2]))->assertForbidden();
            $this->post(route('reservation.exchange.confirm', [$reservation, 2]), [
                'passenger_ids' => [$passenger->id],
                'flight_ids' => [$newLeg2->id],
            ])->assertForbidden();
        }
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
            'status' => FlightStatus::Scheduled,
        ]);
        $leg2 = Flight::factory()->create([
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => now()->addDays(2)->addHours(5),
            'arrives_at' => now()->addDays(2)->addHours(9),
            'checkin_opens_at' => now()->addDays(2)->addHours(2),
            'checkin_closes_at' => now()->addDays(2)->addHours(4),
            'status' => FlightStatus::Scheduled,
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

        return [$reservation, $reservation->passengers->first(), $reservation->farePayment(), $leg1, $leg2];
    }

    /**
     * @return array{0: Reservation, 1: Passenger, 2: Passenger, 3: Flight, 4: Flight}
     */
    private function paidTwoPassengerMultiCity(User $agent): array
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
            [
                [
                    'first_name' => 'Ada',
                    'last_name' => 'One',
                    'type' => PassengerType::Adult->value,
                    'date_of_birth' => '1990-01-01',
                    'cabin_class' => 'Economy',
                    'passport' => ['number' => 'P1', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                    'seats' => [
                        $leg1->id => $this->makeSeat($leg1, '11A')->id,
                        $leg2->id => $this->makeSeat($leg2, '11A')->id,
                    ],
                ],
                [
                    'first_name' => 'Ben',
                    'last_name' => 'Two',
                    'type' => PassengerType::Adult->value,
                    'date_of_birth' => '1991-01-01',
                    'cabin_class' => 'Economy',
                    'passport' => ['number' => 'P2', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                    'seats' => [
                        $leg1->id => $this->makeSeat($leg1, '11B')->id,
                        $leg2->id => $this->makeSeat($leg2, '11B')->id,
                    ],
                ],
            ],
            ['contact_email' => 'pair@example.test'],
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
        );

        $passengers = $reservation->passengers()->orderBy('id')->get();

        return [$reservation, $passengers[0], $passengers[1], $leg1, $leg2];
    }

    private function laterSameRoute(Flight $old, int $days): Flight
    {
        return Flight::factory()->create([
            'flight_no' => $old->flight_no,
            'origin' => $old->origin,
            'destination' => $old->destination,
            'departure_at' => $old->departure_at->copy()->addDays($days),
            'arrives_at' => $old->arrives_at?->copy()->addDays($days) ?? $old->departure_at->copy()->addDays($days)->addHours(4),
            'checkin_opens_at' => $old->checkin_opens_at->copy()->addDays($days),
            'checkin_closes_at' => $old->checkin_closes_at->copy()->addDays($days),
            'status' => FlightStatus::Scheduled,
            'aircraft_id' => $old->aircraft_id,
        ]);
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
            'cabin_class' => 'Economy',
        ]);
    }
}
