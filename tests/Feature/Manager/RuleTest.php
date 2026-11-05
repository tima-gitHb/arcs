<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\Role;
use App\Models\BaggageAllowance;
use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // passport_min_months = 6, Economy 1 x 23 kg, Business 2 x 32 kg
        $this->seed(SettingSeeder::class);
        BaggageAllowance::where('cabin_class', CabinClass::Economy)->update(['pieces' => 1]);
    }

    /**
     * A complete, valid form submission. Each test overrides only the part it checks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'passport_min_months' => 6,
            'allowances' => [
                'economy' => ['pieces' => 1, 'max_kg_per_bag' => 23],
                'premium' => ['pieces' => 2, 'max_kg_per_bag' => 23],
                'business' => ['pieces' => 2, 'max_kg_per_bag' => 32],
            ],
        ], $overrides);
    }

    public function test_SR31_manager_can_change_passport_min_months(): void
    {
        $this->actingAsRole('manager');

        $this->put('/manager/rules', $this->payload(['passport_min_months' => 3]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // settings.value is a string column, so the stored value is '3'.
        $this->assertSame('3', Setting::find('passport_min_months')->value);
    }

    public function test_SR27_manager_can_change_baggage_allowance(): void
    {
        $this->actingAsRole('manager');

        $this->put('/manager/rules', $this->payload([
            'allowances' => ['economy' => ['pieces' => 2, 'max_kg_per_bag' => 25]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $economy = BaggageAllowance::where('cabin_class', CabinClass::Economy)->first();
        $this->assertSame(2, $economy->pieces);
        $this->assertSame('25.0', $economy->max_kg_per_bag);

        // The other cabin class is untouched.
        $business = BaggageAllowance::where('cabin_class', CabinClass::Business)->first();
        $this->assertSame(2, $business->pieces);
        $this->assertSame('32.0', $business->max_kg_per_bag);
    }

    public function test_non_manager_cannot_open_rules_page(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get('/manager/rules')->assertForbidden();
            $this->put('/manager/rules', $this->payload(['passport_min_months' => 3]))->assertForbidden();
        }

        $this->assertSame('6', Setting::find('passport_min_months')->value);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/manager/rules')->assertRedirect(route('login'));
    }

    public function test_negative_values_are_rejected(): void
    {
        $this->actingAsRole('manager');

        $this->put('/manager/rules', $this->payload([
            'passport_min_months' => -1,
            'allowances' => ['economy' => ['pieces' => -1, 'max_kg_per_bag' => -5]],
        ]))->assertSessionHasErrors([
            'passport_min_months',
            'allowances.economy.pieces',
            'allowances.economy.max_kg_per_bag',
        ]);

        $this->assertNothingChanged();
    }

    public function test_values_above_the_column_limit_are_rejected(): void
    {
        $this->actingAsRole('manager');

        // pieces is an unsigned tiny integer (max 255); max_kg_per_bag is decimal(4,1) (max 999.9).
        $this->put('/manager/rules', $this->payload([
            'allowances' => ['economy' => ['pieces' => 256, 'max_kg_per_bag' => 1000]],
        ]))->assertSessionHasErrors([
            'allowances.economy.pieces',
            'allowances.economy.max_kg_per_bag',
        ]);

        $this->assertNothingChanged();
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame('6', Setting::find('passport_min_months')->value);

        $economy = BaggageAllowance::where('cabin_class', CabinClass::Economy)->first();
        $this->assertSame(1, $economy->pieces);
        $this->assertSame('23.0', $economy->max_kg_per_bag);

        $business = BaggageAllowance::where('cabin_class', CabinClass::Business)->first();
        $this->assertSame(2, $business->pieces);
        $this->assertSame('32.0', $business->max_kg_per_bag);
    }
}
