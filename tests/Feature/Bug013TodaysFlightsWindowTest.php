<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUG-013 — today's flights must list a departure after midnight while its check-in window is open.
 */
class Bug013TodaysFlightsWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_BUG013_open_checkin_window_lists_post_midnight_departure_and_skips_tomorrow_closed(): void
    {
        // 22:56 Beirut = 20:56 UTC (Asia/Beirut UTC+2 in November).
        $this->travelTo(Carbon::parse('2026-11-18 22:56', 'Asia/Beirut'));

        $tonight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('264'),
            'origin' => 'BEY',
            'destination' => 'IST',
            // Departs 00:28 tomorrow Beirut = 22:28 UTC same calendar day UTC... use explicit UTC.
            'departure_at' => Carbon::parse('2026-11-19 00:28', 'Asia/Beirut')->utc(),
            'checkin_opens_at' => Carbon::parse('2026-11-18 21:28', 'Asia/Beirut')->utc(),
            'checkin_closes_at' => Carbon::parse('2026-11-18 23:43', 'Asia/Beirut')->utc(),
        ]);

        $tomorrowMorning = Flight::factory()->create([
            'flight_no' => $this->flightNo('110'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-19 10:00', 'Asia/Beirut')->utc(),
            'checkin_opens_at' => Carbon::parse('2026-11-19 07:00', 'Asia/Beirut')->utc(),
            'checkin_closes_at' => Carbon::parse('2026-11-19 09:15', 'Asia/Beirut')->utc(),
        ]);

        $agent = $this->actingAsRole('checkin');

        $this->get(route('checkin.home'))
            ->assertOk()
            ->assertSee($tonight->flight_no)
            ->assertDontSee($tomorrowMorning->flight_no);

        $listed = Flight::query()->departingToday($agent)->departingFrom('BEY')->pluck('flight_no');
        $this->assertTrue($listed->contains($tonight->flight_no));
        $this->assertFalse($listed->contains($tomorrowMorning->flight_no));
    }
}
