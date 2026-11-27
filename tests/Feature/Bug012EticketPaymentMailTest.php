<?php

namespace Tests\Feature;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Mail\ETicketMail;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUG-012 — e-ticket email Amount paid line must be normal text, not raw HTML / a code block.
 */
class Bug012EticketPaymentMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_BUG012_eticket_payment_line_is_plain_text_not_html_code_block(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 20:49', 'UTC'));
        $this->seedBookingPrices();

        $reservation = Reservation::factory()->create();
        $agent = User::factory()->create(['role' => Role::Reservation]);
        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'kind' => PaymentKind::Fare,
            'status' => PaymentStatus::Succeeded,
            'amount_cents' => 25000,
            'currency' => 'USD',
            'method' => 'card',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'gateway_reference' => 'fake_ref_4242',
            'taken_by' => $agent->id,
            'paid_at' => now(),
        ]);

        $html = (new ETicketMail($reservation->fresh(), $payment))->render();

        $this->assertStringContainsString('Visa', $html);
        $this->assertStringContainsString('••••4242', $html);
        $this->assertStringContainsString('fake_ref_4242', $html);
        $this->assertStringNotContainsString('&lt;p', $html);
        $this->assertStringNotContainsString('<pre', $html);
        $this->assertStringNotContainsString('<code', $html);
        $this->assertStringNotContainsString('class="mt-3 text-sm font-medium text-gray-800"', $html);
    }
}
