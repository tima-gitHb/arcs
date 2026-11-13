<?php

namespace Tests\Feature;

use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Mail\ETicketMail;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use App\Support\Pdf;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * B13c — one ARCS email layout for every email, mail settings in .env.example, and a shared PDF helper.
 */
class B13cEmailPdfTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'data-arcs-mail="header"';

    private const FOOTER = 'data-arcs-mail="footer"';

    private function assertArcsLayout(string $html): void
    {
        $this->assertStringContainsString(self::HEADER, $html);
        $this->assertStringContainsString(self::FOOTER, $html);
        $this->assertStringContainsString('Airline Reservation &amp; Check-in System', $html);
        $this->assertStringContainsString('Sent by ARCS to airline staff. Please do not reply to this email.', $html);
        // Laravel's default footer is replaced, not kept beside ours.
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    // --- Email layout -------------------------------------------------------------------------

    public function test_SR36_the_staff_welcome_email_uses_the_arcs_layout(): void
    {
        $user = User::factory()->create(['name' => 'Joe Saliba', 'role' => Role::Gate]);

        $html = (string) (new WelcomeAccount('token-123'))->toMail($user)->render();

        $this->assertArcsLayout($html);
        $this->assertStringContainsString('Hello Joe Saliba,', $html);
        $this->assertStringContainsString('Set your password', $html);
        $this->assertStringContainsString('The ARCS team', $html);
    }

    public function test_the_e_ticket_email_uses_the_arcs_layout(): void
    {
        $this->seedBookingPrices();
        $flight = Flight::factory()->create(['flight_no' => 'ME201']);
        $reservation = Reservation::factory()->for($flight)->create(['pnr' => 'K7M2NP']);
        Passenger::factory()->for($reservation)->create(['first_name' => 'Lea', 'last_name' => 'Morel']);
        $agent = User::factory()->create(['role' => Role::Reservation]);
        $payment = app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'), 1000, PaymentKind::Fee, $agent, reservation: $reservation,
        );

        $html = (new ETicketMail($reservation->fresh(), $payment))->render();

        $this->assertArcsLayout($html);
        $this->assertStringContainsString('E-ticket K7M2NP', $html);
        $this->assertStringContainsString('Lea Morel', $html);
    }

    public function test_the_password_reset_email_uses_the_arcs_layout_too(): void
    {
        $user = User::factory()->create();

        $html = (string) (new ResetPassword('token-123'))->toMail($user)->render();

        $this->assertArcsLayout($html);
    }

    // --- .env.example -------------------------------------------------------------------------

    public function test_env_example_has_the_mailtrap_settings_and_no_credentials(): void
    {
        $env = [];
        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $match)) {
                $env[$match[1]] = $match[2];
            }
        }

        $this->assertSame('smtp', $env['MAIL_MAILER']);
        $this->assertSame('sandbox.smtp.mailtrap.io', $env['MAIL_HOST']);
        $this->assertSame('2525', $env['MAIL_PORT']);
        $this->assertSame('tls', $env['MAIL_ENCRYPTION']);
        $this->assertSame('"noreply@arcs.test"', $env['MAIL_FROM_ADDRESS']);
        $this->assertSame('"ARCS"', $env['MAIL_FROM_NAME']);

        // Never a credential in the repository.
        $this->assertArrayHasKey('MAIL_USERNAME', $env);
        $this->assertArrayHasKey('MAIL_PASSWORD', $env);
        $this->assertSame('', $env['MAIL_USERNAME']);
        $this->assertSame('', $env['MAIL_PASSWORD']);

        // Development can log emails instead of sending them.
        $this->assertStringContainsString('MAIL_MAILER=log', file_get_contents(base_path('.env.example')));
    }

    // --- PDF helper ---------------------------------------------------------------------------

    private function sampleView(): string
    {
        View::addNamespace('b13c', base_path('tests/Fixtures/views'));

        return 'b13c::pdf-sample';
    }

    public function test_the_pdf_helper_returns_a_pdf_download(): void
    {
        $response = $this->createTestResponse(
            Pdf::fromView($this->sampleView(), ['pnr' => 'K7M2NP'], 'eticket-K7M2NP.pdf'),
            null,
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertDownload('eticket-K7M2NP.pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_pdf_helper_gives_the_raw_pdf_for_an_attachment(): void
    {
        $pdf = Pdf::raw($this->sampleView(), ['pnr' => 'K7M2NP']);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }
}
