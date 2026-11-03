<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\Setting;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\PnrGenerator;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class B7StatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private StatusService $status;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->status = app(StatusService::class);
        $this->agent = $this->actingAsRole(Role::Checkin);

        // flightWithStatus() opens the window around now: 09:00 to 11:15, departure 12:00.
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
    }

    private function passenger(Flight $flight, ?string $passportExpiry = '2030-01-01', bool $seated = true): Passenger
    {
        $passenger = Passenger::factory()
            ->for(Reservation::factory()->for($flight))
            ->create();

        if ($passportExpiry) {
            TravelDocument::factory()->for($passenger)->create(['expiry_date' => $passportExpiry]);
        }

        // Check-in needs a seat (C5).
        if ($seated) {
            $this->seatPassenger($passenger);
        }

        return $passenger;
    }

    /**
     * Run the action and check it is refused with exactly this message.
     */
    private function assertRefused(string $message, callable $action): void
    {
        try {
            $action();
        } catch (InvalidStatusChange $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }

        $this->fail("Expected the change to be refused with '{$message}'.");
    }

    public function test_SR6_cancelled_passenger_cannot_check_in(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen));
        $this->status->cancel($passenger, $this->agent);

        $this->assertRefused('Reservation cancelled', fn () => $this->status->checkIn($passenger, $this->agent));
        $this->assertSame(PassengerStatus::Cancelled, $passenger->fresh()->status);
    }

    public function test_check_in_twice_is_refused(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen));
        $this->status->checkIn($passenger, $this->agent);

        $this->assertRefused('Already checked in', fn () => $this->status->checkIn($passenger, $this->agent));
    }

    public function test_SR30_check_in_refused_outside_window(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen));

        $this->travelTo(Carbon::parse('2026-11-10 08:59'));
        $this->assertRefused('Check-in closed', fn () => $this->status->checkIn($passenger, $this->agent));

        $this->travelTo(Carbon::parse('2026-11-10 11:16'));
        $this->assertRefused('Check-in closed', fn () => $this->status->checkIn($passenger, $this->agent));

        $this->travelTo(Carbon::parse('2026-11-10 11:15'));
        $this->status->checkIn($passenger, $this->agent);
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }

    public function test_SR25_check_in_refused_when_flight_not_checkin_open(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::Scheduled)); // still Scheduled

        $this->assertRefused('Check-in closed', fn () => $this->status->checkIn($passenger, $this->agent));
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_SR23_check_in_refused_with_expired_passport(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen), passportExpiry: '2026-11-09');

        $this->assertRefused('Document expired', fn () => $this->status->checkIn($passenger, $this->agent));
    }

    public function test_SR23_check_in_refused_without_travel_document(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen), passportExpiry: null);

        $this->assertRefused('No travel document', fn () => $this->status->checkIn($passenger, $this->agent));
    }

    public function test_SR31_check_in_refused_when_passport_expires_too_soon(): void
    {
        Setting::create(['key' => 'passport_min_months', 'value' => '6']);
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);

        $tooSoon = $this->passenger($flight, passportExpiry: '2027-02-10'); // 3 months after departure
        $this->assertRefused('Document expired', fn () => $this->status->checkIn($tooSoon, $this->agent));

        $justEnough = $this->passenger($flight, passportExpiry: '2027-05-10'); // exactly 6 months
        $this->status->checkIn($justEnough, $this->agent);
        $this->assertSame(PassengerStatus::CheckedIn, $justEnough->fresh()->status);
    }

    public function test_check_in_succeeds_and_writes_audit_log(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen));

        $this->status->checkIn($passenger, $this->agent);

        $passenger->refresh();
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->status);
        $this->assertTrue($passenger->checked_in_at->equalTo(now()));

        $entry = AuditEntry::sole();
        $this->assertSame($passenger->id, $entry->passenger_id);
        $this->assertSame('status', $entry->field);
        $this->assertSame('Booked', $entry->old_value);
        $this->assertSame('CheckedIn', $entry->new_value);
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));
    }

    public function test_SR5_cancel_releases_seat(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);
        $seat = Seat::factory()->create(['aircraft_id' => $flight->aircraft_id]);
        $passenger = $this->passenger($flight, seated: false);
        SeatAssignment::create(['flight_id' => $flight->id, 'seat_id' => $seat->id, 'passenger_id' => $passenger->id]);

        $this->status->cancel($passenger, $this->agent);

        $this->assertSame(PassengerStatus::Cancelled, $passenger->fresh()->status);
        $this->assertDatabaseMissing('seat_assignments', ['passenger_id' => $passenger->id]);
        $this->assertDatabaseHas('audit_log', ['passenger_id' => $passenger->id, 'new_value' => 'Cancelled']);

        // The seat is free for someone else.
        $other = $this->passenger($flight, seated: false);
        SeatAssignment::create(['flight_id' => $flight->id, 'seat_id' => $seat->id, 'passenger_id' => $other->id]);
        $this->assertDatabaseHas('seat_assignments', ['seat_id' => $seat->id, 'passenger_id' => $other->id]);

        // Cancelled is final.
        $this->assertRefused('Only a booked passenger can be cancelled', fn () => $this->status->cancel($passenger, $this->agent));
    }

    public function test_SR10_cannot_board_unless_checked_in(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $booked = $this->passenger($flight);
        $checkedIn = $this->passenger($flight);
        $this->status->checkIn($checkedIn, $this->agent);

        // Flight not boarding yet.
        $this->assertRefused('Boarding has not started', fn () => $this->status->board($checkedIn, $this->agent));

        $this->status->setFlightStatus($flight, FlightStatus::Boarding, $this->agent);

        $this->assertRefused('Passenger not checked in', fn () => $this->status->board($booked, $this->agent));

        $this->status->board($checkedIn, $this->agent);
        $checkedIn->refresh();
        $this->assertSame(PassengerStatus::Boarded, $checkedIn->status);
        $this->assertNotNull($checkedIn->boarded_at);
    }

    public function test_SR12_close_boarding_sets_missing_passengers_no_show_and_returns_their_bags(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $boarded = $this->passenger($flight);
        $missing = $this->passenger($flight);
        $neverCame = $this->passenger($flight);
        $this->status->checkIn($boarded, $this->agent);
        $this->status->checkIn($missing, $this->agent);

        $boardedBag = Bag::factory()->for($boarded)->create();
        $missingBags = Bag::factory()->count(2)->for($missing)->create();

        $this->status->setFlightStatus($flight, FlightStatus::Boarding, $this->agent);
        $this->status->board($boarded, $this->agent);

        $bags = $this->status->closeBoarding($flight, $this->agent);

        $this->assertEqualsCanonicalizing($missingBags->pluck('id')->all(), $bags->pluck('id')->all());
        $this->assertFalse($bags->contains($boardedBag));

        $this->assertSame(PassengerStatus::NoShow, $missing->fresh()->status);
        $this->assertSame(PassengerStatus::Boarded, $boarded->fresh()->status);
        $this->assertSame(PassengerStatus::Booked, $neverCame->fresh()->status);
        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $missing->id, 'old_value' => 'CheckedIn', 'new_value' => 'NoShow',
        ]);

        $flight->refresh();
        $this->assertSame(FlightStatus::Closed, $flight->status);
        $this->assertNotNull($flight->boarding_closed_at);

        $this->assertRefused('Boarding is not open', fn () => $this->status->closeBoarding($flight, $this->agent));
    }

    public function test_flight_status_only_allowed_moves(): void
    {
        $allowed = [
            'Scheduled' => ['CheckinOpen', 'Delayed', 'Cancelled'],
            'CheckinOpen' => ['Boarding', 'Delayed', 'Cancelled'],
            'Boarding' => ['Delayed'], // Closed only through closeBoarding()
            'Delayed' => ['CheckinOpen', 'Boarding', 'Cancelled'],
            'Closed' => ['Departed'],
            'Departed' => [],
            'Cancelled' => [],
        ];

        foreach (FlightStatus::cases() as $from) {
            foreach (FlightStatus::cases() as $to) {
                $flight = $this->flightWithStatus($from);
                $this->assertSame($from, $flight->fresh()->status);
                $shouldWork = in_array($to->value, $allowed[$from->value], true);

                try {
                    $this->status->setFlightStatus($flight, $to, $this->agent);
                    $worked = true;
                } catch (InvalidStatusChange) {
                    $worked = false;
                }

                $this->assertSame($shouldWork, $worked, "{$from->value} -> {$to->value}");
                $this->assertSame($worked ? $to : $from, $flight->fresh()->status);
            }
        }
    }

    public function test_SR1_pnr_is_6_chars_unique_and_has_no_ambiguous_letters(): void
    {
        $generator = app(PnrGenerator::class);
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);
        $pnrs = [];

        for ($i = 0; $i < 50; $i++) {
            $pnr = $generator->generate();
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $pnr);
            $this->assertDoesNotMatchRegularExpression('/[0O1I]/', $pnr);

            Reservation::factory()->for($flight)->create(['pnr' => $pnr]);
            $pnrs[] = $pnr;
        }

        $this->assertCount(50, array_unique($pnrs));
    }

    public function test_SR1_pnr_generator_retries_on_collision(): void
    {
        Reservation::factory()->for($this->flightWithStatus(FlightStatus::Scheduled))->create(['pnr' => 'TAKEN2']);

        $generator = new class extends PnrGenerator
        {
            private array $codes = ['TAKEN2', 'TAKEN2', 'FRESH3'];

            protected function randomCode(): string
            {
                return array_shift($this->codes);
            }
        };

        $this->assertSame('FRESH3', $generator->generate());
    }

    public function test_failed_change_writes_nothing(): void
    {
        $passenger = $this->passenger($this->flightWithStatus(FlightStatus::CheckinOpen));

        // Make the audit insert fail after the status has been saved.
        AuditEntry::creating(fn () => throw new RuntimeException('audit_log unavailable'));

        try {
            $this->status->checkIn($passenger, $this->agent);
            $this->fail('Expected the audit failure to stop the check-in.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit_log unavailable', $e->getMessage());
        }

        $passenger = $passenger->fresh();
        $this->assertSame(PassengerStatus::Booked, $passenger->status);
        $this->assertNull($passenger->checked_in_at);
        $this->assertSame(0, AuditEntry::count());
    }
}
