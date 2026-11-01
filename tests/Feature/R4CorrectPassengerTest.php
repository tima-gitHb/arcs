<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Passenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R4CorrectPassengerTest extends TestCase
{
    use RefreshDatabase;

    public function test_SR33_correcting_a_last_name_shows_both_values_who_and_when(): void
    {
        $this->travelTo('2026-11-01 13:11:00');

        $agent = $this->actingAsRole('reservation');
        $passenger = Passenger::factory()->create([
            'first_name' => 'Rami',
            'last_name' => 'Hadad',
        ]);

        $this->get(route('reservation.search', ['flight' => $passenger->reservation->flight_id]))
            ->assertOk()
            ->assertSee('Correct name');

        $this->get(route('reservation.edit', $passenger))
            ->assertOk()
            ->assertSee('Hadad');

        $this->put(route('reservation.update', $passenger), [
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ])->assertRedirect(route('reservation.edit', $passenger));

        $passenger->refresh();
        $this->assertSame('Rami', $passenger->first_name);
        $this->assertSame('Haddad', $passenger->last_name);

        $entry = AuditEntry::sole();
        $this->assertSame($passenger->id, $entry->passenger_id);
        $this->assertSame('last_name', $entry->field);
        $this->assertSame('Hadad', $entry->old_value);
        $this->assertSame('Haddad', $entry->new_value);
        $this->assertSame($agent->id, $entry->user_id);

        $this->get(route('reservation.edit', $passenger))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('changed from Hadad to Haddad')
            ->assertSee($agent->name)
            ->assertSee('01 Nov 2026 13:11');
    }

    public function test_changing_both_names_writes_one_audit_row_per_field(): void
    {
        $this->actingAsRole('reservation');
        $passenger = Passenger::factory()->create([
            'first_name' => 'Rami',
            'last_name' => 'Hadad',
        ]);

        $this->put(route('reservation.update', $passenger), [
            'first_name' => 'Karim',
            'last_name' => 'Haddad',
        ])->assertRedirect();

        $this->assertSame(2, AuditEntry::where('passenger_id', $passenger->id)->count());
        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id,
            'field' => 'first_name',
            'old_value' => 'Rami',
            'new_value' => 'Karim',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id,
            'field' => 'last_name',
            'old_value' => 'Hadad',
            'new_value' => 'Haddad',
        ]);
    }

    public function test_an_unchanged_name_writes_no_audit_row(): void
    {
        $this->actingAsRole('reservation');
        $passenger = Passenger::factory()->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);

        $this->put(route('reservation.update', $passenger), [
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ])->assertRedirect();

        $this->assertSame(0, AuditEntry::count());
        $this->assertSame('Rami', $passenger->fresh()->first_name);
        $this->assertSame('Haddad', $passenger->fresh()->last_name);
    }
}
