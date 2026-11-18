<?php

namespace Tests\Feature;

use App\Mail\ETicketMail;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\Seat;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * After a paid booking, the contact gets the ARCS-layout e-ticket email with a PDF attachment.
 */
class R15EticketPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_paid_booking_emails_the_eticket_pdf_to_the_contact(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create(['flight_no' => 'ME201']);
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId),
            ],
            ...$this->paidBookingFields([
                'contact_email' => 'lea@example.com',
                'contact_phone' => '+96171123456',
            ]),
        ])->assertRedirect();

        $reservation = Reservation::query()->with('payments')->sole();
        $payment = $reservation->payments->sole();

        Mail::assertSent(ETicketMail::class, function (ETicketMail $mail) use ($reservation): bool {
            if (! $mail->hasTo('lea@example.com') || ! $mail->reservation->is($reservation)) {
                return false;
            }

            $html = $mail->render();
            $this->assertStringContainsString('data-arcs-mail="header"', $html);
            $this->assertStringContainsString('E-ticket '.$reservation->pnr, $html);

            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);
            $this->assertSame('eticket-'.$reservation->pnr.'.pdf', $attachments[0]->as);
            $this->assertSame('application/pdf', $attachments[0]->mime);

            return true;
        });

        $pdf = Pdf::raw('reservation.pdf.eticket', [
            'reservation' => $reservation->fresh([
                'segments.flight',
                'passengers.seatAssignment.seat',
                'passengers.coupons.flight',
                'passengers.coupons.seatAssignment.seat',
            ]),
            'payment' => $payment,
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(500, strlen($pdf));
    }

    public function test_no_email_without_a_contact_address(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
        Mail::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function passenger(int $seatId): array
    {
        return [
            'first_name' => 'Lea',
            'last_name' => 'Morel',
            'type' => 'Adult',
            'cabin_class' => 'Economy',
            'date_of_birth' => '1990-05-01',
            'seat_id' => $seatId,
            'extra_bags' => 0,
            'extra_kg' => 0,
            'passport' => [
                'number' => 'AB1234567',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];
    }

    private function economySeat(Flight $flight): int
    {
        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12A',
            'cabin_class' => 'Economy',
        ])->id;
    }
}
