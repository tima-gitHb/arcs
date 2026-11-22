<?php

namespace Tests\Feature\Validation;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Exceptions\InvalidStatusChange;
use App\Exceptions\SeatNotAllowed;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\SeatService;
use App\Services\SpecialServiceService;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckinGateRulesTest extends TestCase
{
    use RefreshDatabase;

    private StatusService $statuses;

    private SeatService $seats;

    private User $checkin;

    private User $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->seedBookingPrices();
        $this->statuses = app(StatusService::class);
        $this->seats = app(SeatService::class);
        $this->checkin = User::factory()->create(['role' => Role::Checkin]);
        $this->gate = User::factory()->create(['role' => Role::Gate]);
    }

    public function test_exit_row_only_for_adults_without_mobility_or_umnr(): void
    {
        $flight = Flight::factory()->create();
        $exit = Seat::firstOrCreate(
            ['aircraft_id' => $flight->aircraft_id, 'seat_no' => '14A'],
            ['cabin_class' => 'Economy'],
        );
        $normal = Seat::firstOrCreate(
            ['aircraft_id' => $flight->aircraft_id, 'seat_no' => '12A'],
            ['cabin_class' => 'Economy'],
        );

        $child = $this->booked($flight, ['type' => PassengerType::Child, 'date_of_birth' => '2016-01-01']);
        try {
            $this->seats->assign($child, $exit, $this->checkin, $flight);
            $this->fail('Child on exit row should fail');
        } catch (SeatNotAllowed $e) {
            $this->assertSame('Exit-row seats are only for adults.', $e->getMessage());
        }

        $adult = $this->booked($flight);
        $this->seats->assign($adult, $exit, $this->checkin, $flight);
        $this->assertSame('14A', $adult->fresh()->seatAssignment->seat->seat_no);

        $withChair = $this->booked($flight);
        app(SpecialServiceService::class)->add($withChair, SpecialServiceCode::WCHR, null, $this->checkin);
        try {
            $this->seats->assign($withChair, $exit, $this->checkin, $flight);
            $this->fail('WCHR on exit row should fail');
        } catch (SeatNotAllowed $e) {
            $this->assertStringContainsString('WCHR', $e->getMessage());
        }

        $this->seats->assign($withChair, $normal, $this->checkin, $flight);
        $this->assertSame('12A', $withChair->fresh()->seatAssignment->seat->seat_no);
    }

    public function test_assign_enforces_passenger_cabin(): void
    {
        $flight = Flight::factory()->create();
        $economy = Seat::firstOrCreate(
            ['aircraft_id' => $flight->aircraft_id, 'seat_no' => '20B'],
            ['cabin_class' => 'Economy'],
        );
        $business = Seat::firstOrCreate(
            ['aircraft_id' => $flight->aircraft_id, 'seat_no' => '1A'],
            ['cabin_class' => 'Business'],
        );

        $passenger = $this->booked($flight, ['cabin_class' => 'Economy']);

        try {
            $this->seats->assign($passenger, $business, $this->checkin, $flight);
            $this->fail('Wrong cabin should fail');
        } catch (SeatNotAllowed $e) {
            $this->assertSame('Choose a seat in the passenger\'s cabin.', $e->getMessage());
        }

        $this->seats->assign($passenger, $economy, $this->checkin, $flight);
        $this->assertSame('20B', $passenger->fresh()->seatAssignment->seat->seat_no);
    }

    public function test_gate_refuses_unverified_docs_or_unpaid_excess(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = $this->booked($flight);
        $this->seatPassenger($passenger);
        TravelDocument::factory()->for($passenger)->create(['expiry_date' => '2030-01-01']);

        $this->statuses->checkIn($passenger, $this->checkin);
        $this->statuses->setFlightStatus($flight, FlightStatus::Boarding, $this->gate);

        // Simulate a document that was never verified (and undo check-in's acceptance stamp).
        $passenger->travelDocuments()->update(['verified_at' => null, 'verified_by' => null]);

        try {
            $this->statuses->board($passenger, $this->gate, $flight);
            $this->fail('Unverified docs should block boarding');
        } catch (InvalidStatusChange $e) {
            $this->assertSame('Travel documents are not verified', $e->getMessage());
        }

        $this->verifyPassengerDocuments($passenger, $this->checkin);
        $this->statuses->board($passenger, $this->gate, $flight);
        $this->assertSame(PassengerStatus::Boarded, $passenger->fresh()->status);
    }

    public function test_seat_change_refused_while_boarding(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = $this->booked($flight);
        $this->seatPassenger($passenger, '12C');
        TravelDocument::factory()->for($passenger)->create(['expiry_date' => '2030-01-01']);
        $this->statuses->checkIn($passenger, $this->checkin);
        $this->statuses->setFlightStatus($flight, FlightStatus::Boarding, $this->gate);

        $other = Seat::firstOrCreate(
            ['aircraft_id' => $flight->aircraft_id, 'seat_no' => '12D'],
            ['cabin_class' => 'Economy'],
        );

        try {
            $this->seats->change($passenger, $other, $this->checkin, $flight);
            $this->fail('Seat change during boarding should fail');
        } catch (SeatNotAllowed $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booked(Flight $flight, array $attributes = []): Passenger
    {
        return Passenger::factory()
            ->for(Reservation::factory()->for($flight))
            ->create($attributes + [
                'type' => PassengerType::Adult,
                'cabin_class' => 'Economy',
                'date_of_birth' => '1985-04-12',
            ]);
    }
}
