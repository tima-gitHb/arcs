<?php

namespace Tests\Feature\Manager;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M18 — the staff welcome email states the role and station, and can be resent.
 *
 * The role line is "Your role: {label}" plus " · {city} ({CODE})" when a station is set:
 * "Your role: Check-in staff · Paris (CDG)". With no station it is only "Your role: Manager".
 * Check-in and gate with no station also get:
 * "Your station hasn't been set yet. Ask your manager before your first shift."
 * Reservation and manager do not. Greeting, button, sign-off and the ARCS layout stay as they are.
 *
 * Resend is a new route, separate from "Send reset link":
 *   POST /manager/accounts/{user}/resend-welcome    manager.accounts.resend-welcome
 * The button "Resend welcome email" is on a row only when last_login_at is null.
 * The server refuses a locked or deactivated account first ("Unlock or activate the account first."),
 * then an account that has logged in ("This account has already logged in. Use Send reset link instead.").
 * Otherwise Password::sendResetLink sends WelcomeAccount. Within 60 seconds the throttle
 * text is the red error flash, the same as "Send reset link", and the existing token is kept.
 */
class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    private const WARNING = "Your station hasn't been set yet. Ask your manager before your first shift.";

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->actingAsRole(Role::Manager);
    }

    public function test_a_check_in_account_at_cdg_gets_one_welcome_email_with_a_working_link(): void
    {
        $user = $this->createAccount([
            'name' => 'Maya Khoury',
            'email' => 'maya@arcs.test',
            'role' => Role::Checkin->value,
            'airport_code' => 'CDG',
        ]);

        Notification::assertSentToTimes($user, WelcomeAccount::class, 1);
        Notification::assertNotSentTo($user, ResetPassword::class);

        $notification = Notification::sent($user, WelcomeAccount::class)->sole();
        $html = $this->mailHtml($notification, $user);

        $this->assertStringContainsString('Your role: Check-in staff · Paris (CDG)', $html);
        $this->assertStringNotContainsString(self::WARNING, $html);
        $this->assertStringContainsString('Hello Maya Khoury,', $html);
        $this->assertStringContainsString('Set your password', $html);
        $this->assertStringContainsString('The ARCS team', $html);
        $this->assertStringContainsString('data-arcs-mail="header"', $html);
        $this->assertStringContainsString('data-arcs-mail="footer"', $html);
        $this->assertStringContainsString('Airline Reservation &amp; Check-in System', $html);
        $this->assertStringContainsString('Sent by ARCS to airline staff. Please do not reply to this email.', $html);

        $this->assertSame(
            route('password.reset', ['token' => $notification->token, 'email' => $user->email]),
            $notification->toMail($user)->actionUrl,
        );
        $this->assertTrue(Password::broker()->tokenExists($user, $notification->token));
    }

    public function test_a_manager_with_no_station_is_named_by_role_only(): void
    {
        $user = $this->createAccount([
            'name' => 'Hiba Daher',
            'email' => 'hiba@arcs.test',
            'role' => Role::Manager->value,
        ]);

        $html = $this->mailHtml(Notification::sent($user, WelcomeAccount::class)->sole(), $user);

        $this->assertStringContainsString('Your role: Manager', $html);
        // The ARCS footer also uses a middle dot, so only the role line is checked.
        $this->assertStringNotContainsString('Your role: Manager ·', $html);
        $this->assertStringNotContainsString(self::WARNING, $html);
    }

    public function test_check_in_and_gate_with_no_station_are_told_to_ask_their_manager(): void
    {
        $checkin = $this->createAccount([
            'name' => 'Maya Khoury',
            'email' => 'maya@arcs.test',
            'role' => Role::Checkin->value,
        ]);
        $gate = $this->createAccount([
            'name' => 'Joe Saliba',
            'email' => 'joe@arcs.test',
            'role' => Role::Gate->value,
        ]);
        $reservation = $this->createAccount([
            'name' => 'Rami Nassar',
            'email' => 'rami@arcs.test',
            'role' => Role::Reservation->value,
        ]);

        $checkinHtml = $this->mailHtml(Notification::sent($checkin, WelcomeAccount::class)->sole(), $checkin);
        $this->assertStringContainsString('Your role: Check-in staff', $checkinHtml);
        $this->assertStringNotContainsString('Your role: Check-in staff ·', $checkinHtml);
        $this->assertStringContainsString(self::WARNING, $checkinHtml);

        $gateHtml = $this->mailHtml(Notification::sent($gate, WelcomeAccount::class)->sole(), $gate);
        $this->assertStringContainsString('Your role: Gate staff', $gateHtml);
        $this->assertStringContainsString(self::WARNING, $gateHtml);

        $reservationHtml = $this->mailHtml(Notification::sent($reservation, WelcomeAccount::class)->sole(), $reservation);
        $this->assertStringContainsString('Your role: Reservation staff', $reservationHtml);
        $this->assertStringNotContainsString(self::WARNING, $reservationHtml);
    }

    public function test_resend_sends_a_new_welcome_email_and_retires_the_old_link(): void
    {
        $user = $this->createAccount([
            'email' => 'maya@arcs.test',
            'role' => Role::Checkin->value,
            'airport_code' => 'CDG',
        ]);
        $old = Notification::sent($user, WelcomeAccount::class)->sole()->token;
        $this->assertTrue(Password::broker()->tokenExists($user, $old));

        $this->travel(61)->seconds();

        $this->post("/manager/accounts/{$user->id}/resend-welcome")
            ->assertRedirect()
            ->assertSessionHas('success');

        $sent = Notification::sent($user, WelcomeAccount::class);
        $this->assertCount(2, $sent);
        $new = $sent->last()->token;

        $this->assertNotSame($old, $new);
        $this->assertFalse(Password::broker()->tokenExists($user, $old));
        $this->assertTrue(Password::broker()->tokenExists($user, $new));
        $this->assertStringContainsString(
            'Your role: Check-in staff · Paris (CDG)',
            $this->mailHtml($sent->last(), $user),
        );
    }

    public function test_resend_within_60_seconds_is_throttled_and_keeps_the_link(): void
    {
        $user = $this->createAccount(['email' => 'maya@arcs.test', 'role' => Role::Checkin->value]);
        $token = Notification::sent($user, WelcomeAccount::class)->sole()->token;

        $this->post("/manager/accounts/{$user->id}/resend-welcome")
            ->assertRedirect()
            ->assertSessionHas('error', trans('passwords.throttled'));

        Notification::assertSentToTimes($user, WelcomeAccount::class, 1);
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    public function test_resend_is_refused_for_a_locked_or_deactivated_account(): void
    {
        $locked = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CDG']);
        $locked->forceFill(['locked' => true, 'failed_logins' => 5])->save();
        $deactivated = User::factory()->create(['role' => Role::Gate, 'active' => false, 'airport_code' => null]);

        foreach ([$locked, $deactivated] as $user) {
            $this->post("/manager/accounts/{$user->id}/resend-welcome")
                ->assertRedirect()
                ->assertSessionHas('error', 'Unlock or activate the account first.');
        }

        Notification::assertNothingSent();
    }

    public function test_resend_is_refused_when_the_account_has_already_logged_in(): void
    {
        $user = User::factory()->create([
            'role' => Role::Checkin,
            'airport_code' => 'CDG',
            'last_login_at' => now(),
        ]);

        $this->post("/manager/accounts/{$user->id}/resend-welcome")
            ->assertRedirect()
            ->assertSessionHas('error', 'This account has already logged in. Use Send reset link instead.');

        Notification::assertNothingSent();
    }

    public function test_a_locked_account_that_has_logged_in_is_refused_as_locked(): void
    {
        $user = User::factory()->create([
            'role' => Role::Checkin,
            'last_login_at' => now(),
        ]);
        $user->forceFill(['locked' => true, 'failed_logins' => 5])->save();

        $this->post("/manager/accounts/{$user->id}/resend-welcome")
            ->assertRedirect()
            ->assertSessionHas('error', 'Unlock or activate the account first.');

        Notification::assertNothingSent();
    }

    public function test_the_resend_button_is_only_on_accounts_that_have_never_logged_in(): void
    {
        User::factory()->create(['name' => 'Nina New', 'email' => 'nina@arcs.test', 'last_login_at' => null]);
        User::factory()->create(['name' => 'Rita Recent', 'email' => 'rita@arcs.test', 'last_login_at' => now()]);

        $response = $this->get('/manager/accounts')->assertOk();

        $this->assertSame(1, $this->countNodes($response, $this->resendButton('Nina New')));
        $this->assertSame(0, $this->countNodes($response, $this->resendButton('Rita Recent')));
        $this->assertSame(1, $this->countNodes($response, $this->resetButton('Nina New')));
        $this->assertSame(1, $this->countNodes($response, $this->resetButton('Rita Recent')));
    }

    public function test_a_non_manager_cannot_resend_the_welcome_email(): void
    {
        $user = User::factory()->create(['role' => Role::Checkin, 'last_login_at' => null]);

        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);

            $this->post("/manager/accounts/{$user->id}/resend-welcome")->assertForbidden();
        }

        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccount(array $overrides): User
    {
        $form = $overrides + [
            'name' => 'Maya Khoury',
            'email' => 'maya@arcs.test',
            'role' => Role::Checkin->value,
        ];

        $this->post('/manager/accounts', $form)->assertRedirect();

        return User::where('email', $form['email'])->firstOrFail();
    }

    private function mailHtml(WelcomeAccount $notification, User $user): string
    {
        return (string) $notification->toMail($user)->render();
    }

    private function resendButton(string $name): string
    {
        return "//tr[td[contains(., '{$name}')]]//form[contains(@action, '/resend-welcome')]//button[normalize-space()='Resend welcome email']";
    }

    private function resetButton(string $name): string
    {
        return "//tr[td[contains(., '{$name}')]]//button[normalize-space()='Send reset link']";
    }

    private function countNodes(TestResponse $response, string $xpath): int
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();

        return (new DOMXPath($document))->query($xpath)->length;
    }
}
