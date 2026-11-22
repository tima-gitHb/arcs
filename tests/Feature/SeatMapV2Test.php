<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Seat map V2 — horizontal whole-cabin shared component (UI only).
 */
class SeatMapV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->seedBookingPrices();
    }

    public function test_seat_map_v2_horizontal_markup_renders_on_reservation(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('arcs-seat-map-h', false)
            ->assertSee('data-layout="horizontal"', false)
            ->assertSee('arcs-seat-map-panel', false)
            ->assertSee('arcs-plane-fuselage', false)
            ->assertSee('#1B2440', false);
    }

    public function test_seat_map_v2_legend_lists_every_booking_state(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('Economy')
            ->assertSee('Premium')
            ->assertSee('Business')
            ->assertSee('Taken')
            ->assertSee('This passenger')
            ->assertSee('Others in booking')
            ->assertSee('Extra legroom')
            ->assertSee('Not allowed')
            ->assertSee('Selected');
    }

    public function test_seat_map_v2_js_draws_cabin_labels_and_exit_rows(): void
    {
        $js = file_get_contents(resource_path('js/seat-map.js'));

        $this->assertNotFalse($js);
        $this->assertStringContainsString("'BUSINESS'", $js);
        $this->assertStringContainsString("'PREMIUM'", $js);
        $this->assertStringContainsString("'ECONOMY'", $js);
        $this->assertStringContainsString("textContent = 'EXIT'", $js);
        $this->assertStringContainsString('arcs-seat-exit', $js);
        $this->assertStringContainsString('arcs-seat-map-h', $js);
    }

    public function test_seat_map_v2_manager_load_tab_is_read_only_without_passenger_bar(): void
    {
        $this->actingAsRole('manager');

        $aircraft = Aircraft::factory()->create();
        $flight = Flight::factory()->create([
            'aircraft_id' => $aircraft->id,
        ]);

        $this->get(route('manager.flights.show', ['flight' => $flight, 'tab' => 'load']))
            ->assertOk()
            ->assertSee('id="seat-map-panel"', false)
            ->assertSee('arcs-seat-map-h', false)
            ->assertSee('Read-only')
            ->assertDontSee('id="seat-pax-chips"', false)
            ->assertDontSee('arcs-seat-select-bar', false)
            ->assertDontSee('arcs-seat-pax-chip', false);
    }
}
