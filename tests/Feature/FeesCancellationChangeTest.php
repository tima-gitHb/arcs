<?php

namespace Tests\Feature;

use App\Enums\FeeCode;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Fee;
use App\Services\FareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 4 Feature 1 — Cancellation and Change fees (seeded, editable, FareService helpers).
 */
class FeesCancellationChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_cancellation_and_change_fees_are_listed_and_editable_by_manager(): void
    {
        $this->actingAsRole(Role::Manager);

        $this->get(route('manager.fares', ['tab' => 'fees']))
            ->assertOk()
            ->assertSee('Cancellation')
            ->assertSee('Change')
            ->assertSee('name="fees[Cancellation]"', false)
            ->assertSee('name="fees[Change]"', false);

        $this->assertSame(7500, Fee::query()->where('code', FeeCode::Cancellation)->value('amount_cents'));
        $this->assertSame(5000, Fee::query()->where('code', FeeCode::Change)->value('amount_cents'));

        $this->put(route('manager.fares.update'), [
            'fares' => [
                'economy' => ['adult' => '250.00', 'child' => '187.50', 'infant' => '25.00'],
                'premium' => ['adult' => '400.00', 'child' => '300.00', 'infant' => '40.00'],
                'business' => ['adult' => '800.00', 'child' => '600.00', 'infant' => '80.00'],
            ],
            'fees' => [
                FeeCode::ExtraBag->value => '50.00',
                FeeCode::ExcessKg->value => '10.00',
                FeeCode::SportsEquipment->value => '75.00',
                FeeCode::Pet->value => '60.00',
                FeeCode::Cancellation->value => '80.00',
                FeeCode::Change->value => '55.00',
            ],
        ])->assertRedirect(route('manager.fares'));

        $this->assertSame(8000, Fee::query()->where('code', FeeCode::Cancellation)->value('amount_cents'));
        $this->assertSame(5500, Fee::query()->where('code', FeeCode::Change)->value('amount_cents'));

        $this->assertTrue(
            AuditEntry::query()->where('field', 'fee Cancellation')->where('new_value', '$80.00')->exists(),
        );
        $this->assertTrue(
            AuditEntry::query()->where('field', 'fee Change')->where('new_value', '$55.00')->exists(),
        );
    }

    public function test_non_managers_get_403_on_fares_fees(): void
    {
        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);
            $this->get(route('manager.fares', ['tab' => 'fees']))->assertForbidden();
            $this->put(route('manager.fares.update'), ['fees' => []])->assertForbidden();
        }
    }

    public function test_fare_service_cancellation_and_change_helpers_return_totals(): void
    {
        $fares = app(FareService::class);

        $cancel = $fares->cancellationFee(2, 3);
        $this->assertSame(45000, $cancel->totalCents); // 75 × 2 × 3
        $this->assertSame('Cancellation', $cancel->lines[0]['label']);
        $this->assertSame(6, $cancel->lines[0]['quantity']);

        $change = $fares->changeFee(1, 2);
        $this->assertSame(10000, $change->totalCents); // 50 × 1 × 2
        $this->assertSame('Change', $change->lines[0]['label']);

        $this->assertSame(0, $fares->cancellationFee(0, 2)->totalCents);
        $this->assertSame(0, $fares->changeFee(3, 0)->totalCents);
    }
}
