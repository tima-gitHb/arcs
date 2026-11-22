<?php

namespace Tests\Feature\Manager;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Mail\FlightChangeMail;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * M16 — a gate change or a delay with a new departure emails each reservation contact.
 *
 * One mailable, App\Mail\FlightChangeMail, queued (ShouldQueue, afterCommit). The subject is
 * "{flight_no} to {destination city}: new gate {gate}" or "{flight_no} to {destination city}: delayed to {H:i}".
 * The city is the destination airport's city. The clock is the origin airport, via LocalTime::at in the
 * subject and <x-local-time> in the body. A CDG departure is Europe/Paris: 23:30 there is 22:30 UTC,
 * so a Beirut clock (00:30) or a UTC clock (22:30) fails this test. The delay flight is CDG → BEY,
 * so the subject says Beirut while the time is Paris. The gate flight is BEY → CDG, so it says Paris.
 *
 * The Delayed control may post departure_at, typed like the create form (Y-m-d\TH:i, origin local).
 * Empty or omitted: status only, no email. A time that is not later is a departure_at error,
 * "The new departure must be later than the current one.", and nothing is written.
 * A saved time also writes an audit row, field departure_at, old and new in the origin's local time
 * with the same format as <x-local-time> (d M Y H:i).
 *
 * Mail is dispatched with Mail::to() after the change is committed. A throw is caught, logged at
 * error with the flight number, and the success flash is unchanged.
 */
class FlightNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const LATER = 'The new departure must be later than the current one.';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Check-in windows in these tests sit around 10:00 UTC (12:00 in Beirut).
        $this->travelTo('2026-11-10 10:00:00');

        $this->manager = $this->actingAsRole(Role::Manager);
    }

    public function test_a_gate_change_queues_one_email_per_reservation_contact(): void
    {
        Mail::fake();

        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'gate' => 'A3',
        ]);

        // Two coupons on one PNR are still one contact.
        $this->book($flight, 'family@example.com', 2);
        $this->book($flight, 'solo@example.com');
        $this->checkedInPassenger($flight, 'checked@example.com');

        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'B7'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Gate for '.$this->flightNo('201').' saved.');

        $this->assertSame('B7', $flight->fresh()->gate);
        Mail::assertNothingSent();
        Mail::assertQueuedCount(3);

        foreach (['family@example.com', 'solo@example.com', 'checked@example.com'] as $email) {
            $this->assertQueuedTo($email, $this->flightNo('201').' to Paris: new gate B7', function (string $html): void {
                $this->assertStringContainsString('B7', $html);
            });
        }
    }

    public function test_a_cleared_or_unchanged_gate_queues_nothing(): void
    {
        Mail::fake();

        $flight = Flight::factory()->create([
            'flight_no' => $this->flightNo('201'),
            'destination' => 'CDG',
            'gate' => 'A3',
        ]);
        $this->book($flight, 'lea@example.com');

        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'A3'])
            ->assertSessionHasNoErrors();

        $this->assertSame('A3', $flight->fresh()->gate);
        $this->assertSame(0, AuditEntry::where('field', 'gate')->count());

        $this->patch(route('manager.flights.gate', $flight), ['gate' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Gate for '.$this->flightNo('201').' saved.');

        $this->assertNull($flight->fresh()->gate);
        Mail::assertNothingQueued();
    }

    public function test_a_delay_with_a_new_time_queues_the_origin_local_time_and_records_it(): void
    {
        Mail::fake();

        $flight = $this->parisDeparture();
        $this->book($flight, 'delay@example.com');

        $this->patch(route('manager.flights.status', $flight), [
            'status' => 'Delayed',
            'departure_at' => '2026-11-10T23:30',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Flight '.$this->flightNo('201').' is now Delayed.');

        $flight->refresh();
        $this->assertSame(FlightStatus::Delayed, $flight->status);
        $this->assertTrue($flight->departure_at->equalTo(Carbon::parse('2026-11-10 23:30:00', 'Europe/Paris')));

        $entry = AuditEntry::where('field', 'departure_at')->sole();
        $this->assertSame($flight->id, $entry->flight_id);
        $this->assertNull($entry->passenger_id);
        $this->assertSame($this->manager->id, $entry->user_id);
        $this->assertSame('10 Nov 2026 22:00', $entry->old_value);
        $this->assertSame('10 Nov 2026 23:30', $entry->new_value);

        Mail::assertNothingSent();
        Mail::assertQueuedCount(1);
        $this->assertQueuedTo('delay@example.com', $this->flightNo('201').' to Beirut: delayed to 23:30', function (string $html): void {
            $this->assertStringContainsString('datetime="2026-11-10T22:30:00Z"', $html);
            $this->assertStringContainsString('Local time at CDG', $html);
            $this->assertStringContainsString('23:30', $html);
        });
    }

    public function test_a_delay_without_a_new_time_queues_nothing(): void
    {
        Mail::fake();

        $flight = $this->parisDeparture();
        $this->book($flight, 'delay@example.com');
        $departure = $flight->departure_at->copy();

        $this->patch(route('manager.flights.status', $flight), ['status' => 'Delayed'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Flight '.$this->flightNo('201').' is now Delayed.');

        $flight->refresh();
        $this->assertSame(FlightStatus::Delayed, $flight->status);
        $this->assertTrue($flight->departure_at->equalTo($departure));
        $this->assertSame(0, AuditEntry::where('field', 'departure_at')->count());
        Mail::assertNothingQueued();
    }

    public function test_a_new_departure_that_is_not_later_is_refused(): void
    {
        Mail::fake();

        $flight = $this->parisDeparture();
        $this->book($flight, 'delay@example.com');
        $departure = $flight->departure_at->copy();

        // The same instant is not later. 22:00 Paris is the current departure.
        $this->patch(route('manager.flights.status', $flight), [
            'status' => 'Delayed',
            'departure_at' => '2026-11-10T22:00',
        ])->assertSessionHasErrors(['departure_at' => self::LATER]);

        $flight->refresh();
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        $this->assertTrue($flight->departure_at->equalTo($departure));
        $this->assertSame(0, AuditEntry::count());
        Mail::assertNothingQueued();
    }

    public function test_cancelled_boarded_noshow_and_missing_email_get_nothing(): void
    {
        Mail::fake();

        $scheduled = $this->flightWithStatus(FlightStatus::Scheduled, [
            'flight_no' => $this->flightNo('310'),
            'destination' => 'CDG',
            'gate' => 'A1',
        ]);
        $this->cancelOn($scheduled, 'cancelled@example.com');
        $this->book($scheduled, null);
        $this->book($scheduled, 'kept-booked@example.com');

        $boarding = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('311'),
            'destination' => 'CDG',
            'gate' => 'A1',
        ]);
        $boarded = $this->checkedInPassenger($boarding, 'boarded@example.com');
        $this->book($boarding, 'kept-boarding@example.com');
        app(StatusService::class)->setFlightStatus($boarding, FlightStatus::Boarding, $this->manager);
        app(StatusService::class)->board($boarded, $this->manager, $boarding);

        $closed = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('312'),
            'destination' => 'CDG',
            'gate' => 'A1',
        ]);
        $noShow = $this->checkedInPassenger($closed, 'noshow@example.com');
        $this->book($closed, 'kept-closed@example.com');
        app(StatusService::class)->setFlightStatus($closed, FlightStatus::Boarding, $this->manager);
        app(StatusService::class)->closeBoarding($closed, $this->manager);

        $this->assertSame(PassengerStatus::Boarded, $boarded->fresh()->status);
        $this->assertSame(PassengerStatus::NoShow, $noShow->fresh()->status);

        foreach ([$scheduled, $boarding, $closed] as $flight) {
            $this->patch(route('manager.flights.gate', $flight), ['gate' => 'B7'])
                ->assertSessionHasNoErrors();
        }

        Mail::assertQueuedCount(3);
        $this->assertQueuedTo('kept-booked@example.com', $this->flightNo('310').' to Paris: new gate B7');
        $this->assertQueuedTo('kept-boarding@example.com', $this->flightNo('311').' to Paris: new gate B7');
        $this->assertQueuedTo('kept-closed@example.com', $this->flightNo('312').' to Paris: new gate B7');

        foreach (['cancelled@example.com', 'boarded@example.com', 'noshow@example.com'] as $email) {
            Mail::assertNotQueued(FlightChangeMail::class, $email);
        }
    }

    public function test_a_mail_failure_still_saves_the_gate_and_the_delay(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        // The dispatch itself throws. The worker's later SMTP error is outside this request.
        Mail::shouldReceive('to')->twice()->andThrow(new RuntimeException('mail down'));

        $gate = Flight::factory()->create([
            'flight_no' => $this->flightNo('201'),
            'destination' => 'CDG',
            'gate' => 'A3',
        ]);
        $this->book($gate, 'gate@example.com');

        $this->patch(route('manager.flights.gate', $gate), ['gate' => 'B7'])
            ->assertSessionHas('success', 'Gate for '.$this->flightNo('201').' saved.');

        $this->assertSame('B7', $gate->fresh()->gate);

        $delayed = $this->parisDeparture($this->flightNo('202'));
        $this->book($delayed, 'delay@example.com');

        $this->patch(route('manager.flights.status', $delayed), [
            'status' => 'Delayed',
            'departure_at' => '2026-11-10T23:30',
        ])->assertSessionHas('success', 'Flight '.$this->flightNo('202').' is now Delayed.');

        $delayed->refresh();
        $this->assertSame(FlightStatus::Delayed, $delayed->status);
        $this->assertTrue($delayed->departure_at->equalTo(Carbon::parse('2026-11-10 23:30:00', 'Europe/Paris')));

        $messages = collect($logged)
            ->where('level', 'error')
            ->map(fn (MessageLogged $event) => (string) $event->message);

        $this->assertTrue($messages->contains(fn (string $message) => str_contains($message, $this->flightNo('201'))));
        $this->assertTrue($messages->contains(fn (string $message) => str_contains($message, $this->flightNo('202'))));
    }

    public function test_a_flight_change_email_is_retried_when_the_queue_fails(): void
    {
        $flight = $this->parisDeparture();
        $reservation = $this->book($flight, 'delay@example.com');

        $mail = new FlightChangeMail($reservation, $flight, 'delay');

        $this->assertSame(3, $mail->tries);
        $this->assertSame([10, 30], $mail->backoff);
    }

    public function test_a_non_manager_cannot_change_the_gate_or_delay_a_flight(): void
    {
        Mail::fake();

        $flight = $this->parisDeparture();
        $departure = $flight->departure_at->copy();
        $this->book($flight, 'lea@example.com');

        $this->actingAsRole(Role::Checkin);

        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'B7'])->assertForbidden();
        $this->patch(route('manager.flights.status', $flight), [
            'status' => 'Delayed',
            'departure_at' => '2026-11-10T23:30',
        ])->assertForbidden();

        $flight->refresh();
        $this->assertSame('A1', $flight->gate);
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        $this->assertTrue($flight->departure_at->equalTo($departure));
        Mail::assertNothingQueued();
    }

    /**
     * A CDG departure at 22:00 Paris (21:00 UTC). Status stays Scheduled, so Delayed is a legal move.
     */
    private function parisDeparture(?string $flightNo = null): Flight
    {
        return Flight::factory()->create([
            'flight_no' => $flightNo ?? $this->flightNo('201'),
            'origin' => 'CDG',
            'destination' => 'BEY',
            'gate' => 'A1',
            'departure_at' => Carbon::parse('2026-11-10 22:00:00', 'Europe/Paris'),
            'checkin_opens_at' => Carbon::parse('2026-11-10 19:00:00', 'Europe/Paris'),
            'checkin_closes_at' => Carbon::parse('2026-11-10 21:15:00', 'Europe/Paris'),
        ]);
    }

    private function book(Flight $flight, ?string $email, int $passengers = 1): Reservation
    {
        $reservation = Reservation::factory()->for($flight)->create([
            'contact_email' => $email,
        ]);

        Passenger::factory()->count($passengers)->for($reservation)->create();

        return $reservation;
    }

    private function checkedInPassenger(Flight $flight, string $email): Passenger
    {
        $passenger = $this->book($flight, $email)->passengers()->first();
        TravelDocument::factory()->for($passenger)->create(['expiry_date' => '2030-01-01']);
        $this->seatPassenger($passenger);
        app(StatusService::class)->checkIn($passenger, $this->manager);

        return $passenger;
    }

    private function cancelOn(Flight $flight, string $email): void
    {
        $passenger = $this->book($flight, $email)->passengers()->first();
        app(StatusService::class)->cancel($passenger, $this->manager);
    }

    /**
     * The queued mailable is one per address, uses the ARCS layout, and waits until commit.
     */
    private function assertQueuedTo(string $email, string $subject, ?callable $body = null): void
    {
        Mail::assertQueued(FlightChangeMail::class, function ($mail) use ($email, $subject, $body): bool {
            if (! $mail->hasTo($email) || $mail->envelope()->subject !== $subject) {
                return false;
            }

            $this->assertInstanceOf(ShouldQueue::class, $mail);
            $this->assertTrue($mail->afterCommit === true);

            $html = $mail->render();
            $this->assertStringContainsString('data-arcs-mail="header"', $html);
            $this->assertStringContainsString('data-arcs-mail="footer"', $html);

            if ($body !== null) {
                $body($html);
            }

            return true;
        });
    }
}
