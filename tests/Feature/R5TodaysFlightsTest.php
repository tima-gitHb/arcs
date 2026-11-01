<?php

namespace Tests\Feature;

use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class R5TodaysFlightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_SR41_reservation_home_shows_only_todays_flights_sorted_by_departure(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->actingAsRole('reservation');

        Flight::factory()->create(['flight_no' => 'ME900', 'departure_at' => '2026-11-09 18:00']);
        Flight::factory()->create([
            'flight_no' => 'ME815',
            'destination' => 'FCO',
            'gate' => 'B4',
            'departure_at' => '2026-11-10 15:00',
        ]);
        Flight::factory()->create([
            'flight_no' => 'ME801',
            'destination' => 'CDG',
            'gate' => 'A2',
            'departure_at' => '2026-11-10 08:00',
        ]);
        Flight::factory()->create(['flight_no' => 'ME950', 'departure_at' => '2026-11-11 09:00']);

        $response = $this->get(route('reservation.create'));

        $response->assertOk()->assertSee("Today's flights", false);

        preg_match('/data-role="reservation">[\s\S]*?<\/div>/', $response->getContent(), $widget);

        $this->assertArrayHasKey(0, $widget);
        $this->assertStringContainsString('ME801', $widget[0]);
        $this->assertStringContainsString('ME815', $widget[0]);
        $this->assertLessThan(strpos($widget[0], 'ME815'), strpos($widget[0], 'ME801'));
        $this->assertStringNotContainsString('ME900', $widget[0]);
        $this->assertStringNotContainsString('ME950', $widget[0]);
        $this->assertStringContainsString('08:00', $widget[0]);
        $this->assertStringContainsString('A2', $widget[0]);
        $this->assertStringContainsString('15:00', $widget[0]);
        $this->assertStringContainsString('B4', $widget[0]);
    }
}
