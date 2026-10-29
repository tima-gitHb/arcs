<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B4RolesTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $password = 'password')
    {
        return $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    public function test_SR13_each_role_is_redirected_to_its_own_home(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->login($user)->assertRedirect(route($role->homeRoute(), absolute: false));
            $this->get('/dashboard')->assertRedirect(route($role->homeRoute()));
            $this->get('/')->assertRedirect(route($role->homeRoute()));
            $this->followingRedirects()->get(route($role->homeRoute()))->assertOk()->assertSee($role->label());

            $this->post('/logout');
        }
    }

    public function test_SR13_checkin_user_gets_403_on_manager_pages(): void
    {
        $checkin = User::factory()->create(['role' => Role::Checkin]);
        $gate = User::factory()->create(['role' => Role::Gate]);

        $this->actingAs($checkin)->get('/manager')->assertForbidden();
        $this->actingAs($gate)->get('/checkin')->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/checkin')->assertRedirect(route('login'));
    }

    public function test_SR15_account_locks_after_5_failed_logins(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 4; $i++) {
            $this->login($user, 'wrong-password');
        }
        $user->refresh();
        $this->assertSame(4, $user->failed_logins);
        $this->assertFalse($user->locked);

        $this->login($user, 'wrong-password');
        $user->refresh();
        $this->assertSame(5, $user->failed_logins);
        $this->assertTrue($user->locked);
        $this->assertGuest();
    }

    public function test_SR15_locked_account_cannot_log_in_with_correct_password(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['failed_logins' => 5, 'locked' => true])->save();

        $this->login($user)->assertSessionHasErrors([
            'email' => 'Account locked. Ask the Manager to unlock it.',
        ]);

        $this->assertGuest();
    }

    public function test_successful_login_resets_failed_logins(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['failed_logins' => 3])->save();

        $this->login($user);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->fresh()->failed_logins);
    }

    public function test_deactivated_account_cannot_log_in(): void
    {
        $user = User::factory()->create(['active' => false]);

        $this->login($user)->assertSessionHasErrors([
            'email' => 'This account is deactivated.',
        ]);

        $this->assertGuest();
    }
}
