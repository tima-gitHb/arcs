<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Mail\ETicketMail;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\JourneyService;
use App\Support\ConnectionTime;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * C20 — check-in and e-ticket show the same ConnectionTime label for a connection.
 */
class C20ConnectionTimeDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_and_eticket_share_connection_time_label(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->seedBookingPrices();

        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('264'),
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('216'),
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:05', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 14:30', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 10:20', 'UTC'),
        ]);

        $label = ConnectionTime::label($first, $second);
        $this->assertSame('1 h 05 min', $label);

        $reservation = Reservation::factory()->create(['flight_id' => $first->id]);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));
        $passenger = Passenger::factory()->for($reservation)->create();
        TravelDocument::factory()->for($passenger)->create(['issuing_country' => 'LB']);
        foreach ($passenger->coupons as $coupon) {
            $seat = Seat::factory()->create(['aircraft_id' => $coupon->flight->aircraft_id]);
            SeatAssignment::create([
                'flight_id' => $coupon->flight_id,
                'seat_id' => $seat->id,
                'passenger_id' => $passenger->id,
                'coupon_id' => $coupon->id,
            ]);
        }

        $agent = User::factory()->create(['role' => Role::Reservation]);
        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Fare,
            'status' => PaymentStatus::Succeeded,
            'amount_cents' => 40000,
            'currency' => 'USD',
            'method' => 'card',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'gateway_reference' => 'fake_conn_1',
            'taken_by' => $agent->id,
            'paid_at' => now(),
        ]);

        $this->actingAsRole('checkin');
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee($label);

        $mail = (new ETicketMail($reservation->fresh(['segments.flight', 'passengers.coupons.seatAssignment.seat']), $payment))->render();
        $this->assertStringContainsString($label, $mail);
    }
}
