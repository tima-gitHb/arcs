<?php

namespace Tests\Feature\Manager;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * M20 / CR-11 — temporary password at first login.
 *
 * Create shows a temporary password once (session flash). The account has
 * must_change_password. Login still lands on the role home, then middleware
 * sends the user to "Choose a new password". Other pages redirect there too;
 * logout still works. After the change, the flag is cleared and the role home
 * is reachable. The welcome email tells them to use the Manager's temporary
 * password and never includes that password.
 */
class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_account_shows_the_temporary_password_once_and_flags_the_user(): void
    {
        Notification::fake();
        $this->actingAsRole(Role::Manager);

        $response = $this->post('/manager/accounts', [
            'name' => 'Maya Khoury',
            'email' => 'maya@arcs.test',
            'role' => Role::Checkin->value,
            'airport_code' => 'CDG',
        ])->assertRedirect();

        $user = User::where('email', 'maya@arcs.test')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($response->getSession()->get('temporary_password'), $user->password));

        $password = $response->getSession()->get('temporary_password');
        $this->assertIsString($password);
        $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertGreaterThanOrEqual(8, strlen($password));

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee($password, false);

        // A later visit has no flash: the password is not shown again.
        $this->get(route('manager.accounts.show', $user))
            ->assertOk()
            ->assertDontSee($password, false);
    }

    public function test_the_welcome_email_mentions_the_temporary_password_but_does_not_include_it(): void
    {
        Notification::fake();
        $this->actingAsRole(Role::Manager);

        $response = $this->post('/manager/accounts', [
            'name' => 'Maya Khoury',
            'email' => 'maya@arcs.test',
            'role' => Role::Checkin->value,
            'airport_code' => 'CDG',
        ])->assertRedirect();

        $user = User::where('email', 'maya@arcs.test')->firstOrFail();
        $password = $response->getSession()->get('temporary_password');

        Notification::assertSentTo($user, WelcomeAccount::class, function (WelcomeAccount $notification) use ($user, $password) {
            $html = (string) $notification->toMail($user)->render();

            $this->assertStringContainsString('temporary password', strtolower($html));
            $this->assertStringContainsString('manager', strtolower($html));
            $this->assertStringNotContainsString('Set your password', $html);
            $this->assertStringNotContainsString($password, $html);

            return true;
        });
    }

    public function test_first_login_is_sent_to_the_change_password_page(): void
    {
        $user = $this->newStaff();

        $this->post('/login', ['email' => $user->email, 'password' => 'TempPass1'])
            ->assertRedirect(route($user->role->homeRoute()));

        $this->get(route($user->role->homeRoute()))
            ->assertRedirect(route('password.force'));

        $this->get(route('password.force'))
            ->assertOk()
            ->assertSee('Choose a new password');
    }

    public function test_other_pages_also_redirect_until_the_password_is_changed(): void
    {
        $user = $this->newStaff();
        $this->post('/login', ['email' => $user->email, 'password' => 'TempPass1']);

        $this->get(route('profile.edit'))->assertRedirect(route('password.force'));
        $this->get(route('checkin.home'))->assertRedirect(route('password.force'));

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_after_changing_the_password_the_flag_is_cleared_and_the_home_page_opens(): void
    {
        $user = $this->newStaff();
        $this->post('/login', ['email' => $user->email, 'password' => 'TempPass1']);

        $this->from(route('password.force'))
            ->put(route('password.force.update'), [
                'password' => 'NewPass99',
                'password_confirmation' => 'NewPass99',
            ])
            ->assertRedirect(route($user->role->homeRoute()));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewPass99', $user->password));

        $this->get(route($user->role->homeRoute()))->assertOk();
    }

    private function newStaff(): User
    {
        return User::factory()->create([
            'email' => 'maya@arcs.test',
            'password' => 'TempPass1',
            'role' => Role::Checkin,
            'airport_code' => 'CDG',
            'must_change_password' => true,
        ]);
    }
}
