<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI1 — every logged-in home renders inside the app shell with its role badge and own menu.
 */
class UI1ShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_UI_each_role_home_renders_in_the_shell_with_its_badge(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role, 'airport_code' => 'BEY']);

            $this->actingAs($user)
                ->followingRedirects()
                ->get(route($role->homeRoute()))
                ->assertOk()
                ->assertSee('data-arcs-shell', false)
                ->assertSee($role->label())
                ->assertSee('data-role-badge', false);
        }
    }

    public function test_UI_each_menu_shows_only_its_own_links(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role, 'airport_code' => 'BEY']);
            $response = $this->actingAs($user)->followingRedirects()->get(route($role->homeRoute()))->assertOk();

            foreach (Role::cases() as $other) {
                foreach (array_keys($other->menu()) as $label) {
                    $other === $role
                        ? $response->assertSee($label)
                        : $response->assertDontSee($label);
                }
            }
        }
    }

    public function test_UI_a_guest_gets_the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('ARCS')
            ->assertDontSee('data-arcs-shell', false);
    }
}
