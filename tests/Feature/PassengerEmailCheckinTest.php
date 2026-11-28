<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Mail\CheckInCompleteMail;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\Payments\CardDetails;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CR-12 — passenger email at check-in (and optional email at booking).
 */
class PassengerEmailCheckinTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
    }

    public function test_SR_CR12_checkin_agent_adds_email_saved_audited_and_mail_goes_there(): void
    {
        Mail::fake();
        $passenger = $this->bookedPassenger(contactEmail: 'contact@example.test');

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Email for boarding passes')
            ->assertSee('Booking contact: contact@example.test');

        $this->put(route('checkin.passengers.email', $passenger), [
            'email' => 'pax@example.test',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Email saved');

        $this->assertSame('pax@example.test', $passenger->fresh()->email);
        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'email')
                ->where('new_value', 'pax@example.test')
                ->exists()
        );

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('pax@example.test');

        $this->seatPassenger($passenger);
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        Mail::assertSent(CheckInCompleteMail::class, fn (CheckInCompleteMail $mail) => $mail->hasTo('pax@example.test'));

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Email sent to pax@example.test')
            ->assertSee('Resend email');
    }

    public function test_SR_CR12_clearing_email_uses_contact_and_no_email_skips_mail(): void
    {
        Mail::fake();
        $passenger = $this->bookedPassenger(contactEmail: 'contact@example.test');
        $passenger->forceFill(['email' => 'pax@example.test'])->save();

        $this->put(route('checkin.passengers.email', $passenger), [
            'email' => '',
        ])->assertSessionHas('success', 'Email saved');

        $this->assertNull($passenger->fresh()->email);

        $this->seatPassenger($passenger);
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');
        Mail::assertSent(CheckInCompleteMail::class, fn (CheckInCompleteMail $mail) => $mail->hasTo('contact@example.test'));

        Mail::fake();
        $none = $this->bookedPassenger(contactEmail: null);
        $this->seatPassenger($none);
        $this->post(route('checkin.passengers.confirm', $none))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();
        Mail::assertNothingSent();
        $this->assertSame(PassengerStatus::CheckedIn, $none->fresh()->status);
    }

    public function test_SR_CR12_invalid_email_refused_and_station_and_roles_are_403(): void
    {
        $passenger = $this->bookedPassenger();

        $this->put(route('checkin.passengers.email', $passenger), [
            'email' => 'not-an-email',
        ])->assertSessionHasErrors('email');
        $this->assertNull($passenger->fresh()->email);

        $other = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CDG']);
        $this->actingAs($other);
        $this->put(route('checkin.passengers.email', $passenger), [
            'email' => 'ok@example.test',
        ])->assertForbidden();

        foreach ([Role::Reservation, Role::Gate, Role::Manager] as $role) {
            $this->actingAsRole($role);
            $this->put(route('checkin.passengers.email', $passenger), [
                'email' => 'ok@example.test',
            ])->assertForbidden();
            $this->post(route('checkin.passengers.email.resend', $passenger))->assertForbidden();
        }
    }

    public function test_SR_CR12_resend_sends_again_and_is_audited(): void
    {
        Mail::fake();
        $passenger = $this->bookedPassenger(contactEmail: 'contact@example.test');
        $passenger->forceFill(['email' => 'pax@example.test'])->save();
        $this->seatPassenger($passenger);
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');
        Mail::assertSentCount(1);

        $this->post(route('checkin.passengers.email.resend', $passenger))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSentCount(2);
        Mail::assertSent(CheckInCompleteMail::class, fn (CheckInCompleteMail $mail) => $mail->hasTo('pax@example.test'));
        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'checkin_email_resent')
                ->where('new_value', 'pax@example.test')
                ->exists()
        );
    }

    public function test_SR_CR12_reservation_saves_passenger_email_when_booking(): void
    {
        $agent = $this->actingAsRole(Role::Reservation);
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
            [[
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
                'type' => PassengerType::Adult->value,
                'date_of_birth' => '1990-01-01',
                'cabin_class' => 'Economy',
                'passport' => ['number' => 'P1', 'issuing_country' => 'LB', 'expiry_date' => '2030-01-01'],
                'seat_id' => Seat::factory()->create(['aircraft_id' => $flight->aircraft_id, 'seat_no' => '12A'])->id,
            ]],
            ['contact_email' => 'booker@example.test'],
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
        );

        $this->assertSame('ada@example.test', $reservation->passengers->first()->email);
    }

    private function bookedPassenger(?string $contactEmail = 'contact@example.test'): Passenger
    {
        $reservation = Reservation::factory()->for($this->flight)->create([
            'contact_email' => $contactEmail,
        ]);
        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'email' => null,
        ]);
        TravelDocument::factory()->for($passenger)->create();

        return $passenger->fresh(['reservation']);
    }
}
