<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Limits;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B14 — rule limits (CR-15) and the app-shell example on the check-in find page.
 */
class B14LimitsAndShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_B14_every_limit_is_readable_with_its_default(): void
    {
        $this->assertSame(16, Limits::childAloneMinAge());
        $this->assertSame(5, Limits::umnrMinAge());
        $this->assertSame(1, Limits::maxInfantsPerAdult());
        $this->assertSame(9, Limits::maxPassengersPerBooking());
        $this->assertSame(32, Limits::maxBagKg());
        $this->assertSame(220, Limits::maxAircraftSeats());
        $this->assertSame(45, Limits::minTurnaroundMinutes());
        $this->assertSame(24, Limits::maxConnectionHours());
        $this->assertSame(2, Limits::maxPetsInCabin());
        $this->assertSame(6, Limits::maxLegs());
    }

    public function test_B14_checkin_find_page_renders_inside_the_shell(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => Role::Checkin,
            'airport_code' => 'BEY',
            'email' => 'checkin@arcs.test',
        ]));

        $this->get(route('checkin.home'))
            ->assertOk()
            ->assertSee('data-arcs-shell', false)
            ->assertSee('Check in passenger');
    }

    public function test_B14_other_roles_still_get_403_on_checkin_home(): void
    {
        foreach ([Role::Reservation, Role::Gate, Role::Manager] as $role) {
            $this->actingAs(User::factory()->create([
                'role' => $role,
                'airport_code' => 'BEY',
            ]));

            $this->get(route('checkin.home'))->assertForbidden();
        }
    }
}
