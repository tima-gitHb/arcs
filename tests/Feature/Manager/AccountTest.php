<?php

namespace Tests\Feature\Manager;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A valid "create account" form. Each test overrides only what it checks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newAccount(array $overrides = []): array
    {
        return $overrides + ['name' => 'Joe Gate', 'email' => 'joe@arcs.test', 'role' => 'gate'];
    }

    /**
     * Log out whoever is logged in (guard + session), so the next request is a guest's.
     */
    private function logOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_SR22_new_gate_account_sees_only_gate_pages(): void
    {
        $this->actingAsRole('manager');

        $this->post('/manager/accounts', $this->newAccount())->assertRedirect();

        $new = User::where('email', 'joe@arcs.test')->firstOrFail();
        $this->assertSame(Role::Gate, $new->role);
        $this->assertTrue($new->active);
        $this->assertFalse($new->locked);

        // M20: clear the first-login flag so this test can check role and station pages.
        $new->forceFill(['must_change_password' => false])->save();

        // No station yet (B13a): the Manager sets it (the dropdown is card M13).
        $this->actingAs($new);
        $this->get('/gate')->assertForbidden()->assertSee('No station set: ask your manager');
        $new->update(['airport_code' => 'BEY']);
        $this->get('/gate')->assertOk();
        $this->get('/checkin')->assertForbidden();
        $this->get('/reservation/create')->assertForbidden();
        $this->get('/manager')->assertForbidden();
        $this->get('/manager/accounts')->assertForbidden();
    }

    public function test_SR22_deactivated_account_cannot_log_in(): void
    {
        $user = User::factory()->create(['role' => Role::Gate]);
        $this->actingAsRole('manager');

        $this->patch("/manager/accounts/{$user->id}/active", ['active' => 0])->assertRedirect();
        $this->assertFalse($user->fresh()->active);

        $this->logOut();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This account is deactivated.']);
        $this->assertGuest();

        // Activating it again lets the person in.
        $this->actingAsRole('manager');
        $this->patch("/manager/accounts/{$user->id}/active", ['active' => 1])->assertRedirect();
        $this->assertTrue($user->fresh()->active);

        $this->logOut();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_SR35_manager_unlocks_account_locked_after_5_failed_logins(): void
    {
        $user = User::factory()->create(['role' => Role::Gate]);

        // Lock it the real way: 5 wrong passwords, like B4RolesTest.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->assertTrue($user->fresh()->locked);
        $this->assertSame(5, $user->fresh()->failed_logins);

        $this->actingAsRole('manager');
        $this->patch("/manager/accounts/{$user->id}/unlock")->assertRedirect();

        $this->assertFalse($user->fresh()->locked);
        $this->assertSame(0, $user->fresh()->failed_logins);

        // The 5 failed attempts also tripped the login rate limiter (60 seconds); let it expire.
        $this->travel(2)->minutes();
        $this->logOut();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_SR36_creating_account_sends_welcome_email_about_the_temporary_password(): void
    {
        Notification::fake();
        $this->actingAsRole('manager');

        $this->post('/manager/accounts', $this->newAccount())->assertRedirect();

        $user = User::where('email', 'joe@arcs.test')->firstOrFail();

        // A hashed temporary password is stored; the plain value was flashed once to the Manager.
        $this->assertNotEmpty($user->password);
        $this->assertTrue($user->must_change_password);

        Notification::assertSentTo($user, WelcomeAccount::class, function (WelcomeAccount $notification) use ($user) {
            $html = (string) $notification->toMail($user)->render();
            $this->assertStringContainsString('temporary password', strtolower($html));
            $this->assertStringContainsString('manager', strtolower($html));
            $this->assertStringNotContainsString('Set your password', $html);
            $this->assertNull($notification->toMail($user)->actionUrl);

            return true;
        });
    }

    public function test_SR40_list_shows_last_login(): void
    {
        User::factory()->create(['name' => 'Rita Recent', 'email' => 'rita@arcs.test', 'last_login_at' => Carbon::parse('2026-11-10 08:30:00', 'Asia/Beirut')]);
        User::factory()->create(['name' => 'Nina New', 'email' => 'nina@arcs.test', 'last_login_at' => null]);
        User::factory()->create(['name' => 'Larry Locked', 'email' => 'larry@arcs.test', 'locked' => true, 'failed_logins' => 5]);

        $this->actingAsRole('manager');

        $this->get('/manager/accounts')
            ->assertOk()
            ->assertSee('Rita Recent')
            ->assertSee('rita@arcs.test')
            ->assertSee('2026-11-10 08:30')
            ->assertSee('Never')
            ->assertSee('Locked');
    }

    public function test_send_reset_link_sends_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => Role::Gate]);
        $this->actingAsRole('manager');

        $this->post("/manager/accounts/{$user->id}/reset-link")
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPassword::class);

        // A second request within 60 seconds is throttled: its message is shown, not an error page.
        $this->post("/manager/accounts/{$user->id}/reset-link")
            ->assertRedirect()
            ->assertSessionHas('error', trans('passwords.throttled'));

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_reset_link_is_refused_for_locked_or_deactivated_account(): void
    {
        Notification::fake();
        $locked = User::factory()->create();
        $locked->forceFill(['locked' => true, 'failed_logins' => 5])->save();
        $deactivated = User::factory()->create(['active' => false]);

        $this->actingAsRole('manager');

        foreach ([$locked, $deactivated] as $user) {
            $this->post("/manager/accounts/{$user->id}/reset-link")
                ->assertRedirect()
                ->assertSessionHas('error', 'Unlock or activate the account first.');
        }

        Notification::assertNothingSent();
    }

    public function test_duplicate_email_and_invalid_role_are_rejected(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@arcs.test']);
        $this->actingAsRole('manager');
        $before = User::count();

        $this->post('/manager/accounts', $this->newAccount(['email' => 'taken@arcs.test']))
            ->assertSessionHasErrors('email');

        $this->post('/manager/accounts', $this->newAccount(['role' => 'wizard']))
            ->assertSessionHasErrors('role');

        $this->assertSame($before, User::count());
        Notification::assertNothingSent();
    }

    public function test_manager_cannot_deactivate_own_account(): void
    {
        $manager = $this->actingAsRole('manager');

        $this->patch("/manager/accounts/{$manager->id}/active", ['active' => 0])
            ->assertRedirect()
            ->assertSessionHas('error', 'You cannot deactivate your own account.');

        $this->assertTrue($manager->fresh()->active);
    }

    public function test_non_manager_cannot_open_or_post_to_accounts(): void
    {
        Notification::fake();
        $target = User::factory()->create(['role' => Role::Gate]);

        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);

            $this->get('/manager/accounts')->assertForbidden();
            $this->post('/manager/accounts', $this->newAccount())->assertForbidden();
            $this->patch("/manager/accounts/{$target->id}/active", ['active' => 0])->assertForbidden();
            $this->patch("/manager/accounts/{$target->id}/unlock")->assertForbidden();
            $this->post("/manager/accounts/{$target->id}/reset-link")->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'joe@arcs.test']);
        $this->assertTrue($target->fresh()->active);
        Notification::assertNothingSent();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/manager/accounts')->assertRedirect(route('login'));
        $this->post('/manager/accounts', $this->newAccount())->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'joe@arcs.test']);
    }
}
