<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Mail\CheckInCompleteMail;
use App\Models\AuditEntry;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * C17 — check-in complete email with boarding-pass and bag-tag PDFs (SR-79).
 */
class C17CheckinEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME201',
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
    }

    private function passenger(?string $contactEmail = 'rami@example.com'): Passenger
    {
        $reservation = Reservation::factory()->for($this->flight)->create([
            'pnr' => 'K7M2NP',
            'contact_email' => $contactEmail,
        ]);
        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger, '14C');

        return $passenger->fresh();
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME264',
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => 'ME216',
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:15', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 10:45', 'UTC'),
        ]);

        return [$first, $second];
    }

    private function seatCoupon(Coupon $coupon): void
    {
        $row = 1;
        while (Seat::query()
            ->where('aircraft_id', $coupon->flight->aircraft_id)
            ->where('seat_no', $row.'A')
            ->exists()) {
            $row++;
        }

        $seat = Seat::factory()->create([
            'aircraft_id' => $coupon->flight->aircraft_id,
            'seat_no' => $row.'A',
        ]);
        SeatAssignment::create([
            'flight_id' => $coupon->flight_id,
            'seat_id' => $seat->id,
            'passenger_id' => $coupon->passenger_id,
            'coupon_id' => $coupon->id,
        ]);
    }

    public function test_SR79_check_in_emails_boarding_pass_and_bag_tag_pdfs(): void
    {
        Mail::fake();

        $passenger = $this->passenger();
        app(BagService::class)->add($passenger, 18, BagType::Standard, $this->agent);
        app(BagService::class)->add($passenger, 12, BagType::Standard, $this->agent);

        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);

        Mail::assertSent(CheckInCompleteMail::class, function (CheckInCompleteMail $mail) use ($passenger): bool {
            if (! $mail->hasTo('rami@example.com') || ! $mail->passenger->is($passenger)) {
                return false;
            }

            $html = $mail->render();
            $this->assertStringContainsString('data-arcs-mail="header"', $html);
            $this->assertStringContainsString('Check-in complete', $html);
            $this->assertStringContainsString('K7M2NP', $html);

            // One boarding pass + two bag tags.
            $this->assertCount(3, $mail->attachments());

            return true;
        });
    }

    public function test_SR79_connection_attaches_two_boarding_passes(): void
    {
        Mail::fake();

        [$first, $second] = $this->connection();
        $reservation = Reservation::factory()->create([
            'flight_id' => $first->id,
            'pnr' => 'C0N3CT',
            'contact_email' => 'lea@example.com',
        ]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));

        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Lea',
            'last_name' => 'Khoury',
        ]);
        TravelDocument::factory()->for($passenger)->create();
        foreach ($passenger->coupons as $coupon) {
            $this->seatCoupon($coupon);
        }

        app(BoardingPassService::class)->completeCheckIn($passenger->fresh(), $this->agent);

        Mail::assertSent(CheckInCompleteMail::class, function (CheckInCompleteMail $mail): bool {
            $this->assertCount(2, $mail->attachments());

            return $mail->hasTo('lea@example.com');
        });
    }

    public function test_SR79_no_email_skips_mail_without_error(): void
    {
        Mail::fake();

        $passenger = $this->passenger(contactEmail: null);

        $this->post(route('checkin.passengers.confirm', $passenger))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_SR79_mail_failure_does_not_block_check_in(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail down'));

        $passenger = $this->passenger();

        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $this->assertNotNull($passenger->fresh()->activeBoardingPass);

        $this->assertTrue(
            collect($logged)->where('level', 'error')->contains(
                fn (MessageLogged $event) => str_contains((string) $event->message, 'Check-in email')
            )
        );

        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'checkin_email_failed')
                ->exists()
        );
    }

    public function test_SR79_reprint_does_not_resend_the_mail(): void
    {
        Mail::fake();

        $passenger = $this->passenger();
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');
        Mail::assertSentCount(1);

        $this->post(route('checkin.boarding-pass.reprint', $passenger))->assertSessionHas('success');

        Mail::assertSentCount(1);
    }
}
