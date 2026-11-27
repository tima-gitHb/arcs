<?php

namespace Tests\Unit;

use App\Models\Flight;
use App\Support\ConnectionTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConnectionTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_formats_under_one_hour_as_minutes_only(): void
    {
        [$previous, $next] = $this->pair(45);

        $this->assertSame('45 min', ConnectionTime::label($previous, $next));
        $this->assertSame(45, ConnectionTime::minutes($previous, $next));
    }

    public function test_formats_one_hour_five_with_padded_minutes(): void
    {
        [$previous, $next] = $this->pair(65);

        $this->assertSame('1 h 05 min', ConnectionTime::label($previous, $next));
    }

    public function test_formats_two_hours_ten(): void
    {
        [$previous, $next] = $this->pair(130);

        $this->assertSame('2 h 10 min', ConnectionTime::label($previous, $next));
    }

    public function test_negative_gap_returns_error_label(): void
    {
        $previous = Flight::factory()->create([
            'arrives_at' => Carbon::parse('2026-11-15 12:00', 'UTC'),
        ]);
        $next = Flight::factory()->create([
            'departure_at' => Carbon::parse('2026-11-15 11:00', 'UTC'),
        ]);

        $this->assertSame(ConnectionTime::ERROR_LABEL, ConnectionTime::label($previous, $next));
        $this->assertLessThan(0, ConnectionTime::minutes($previous, $next));
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function pair(int $gapMinutes): array
    {
        $previous = Flight::factory()->create([
            'destination' => 'IST',
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
        ]);
        $next = Flight::factory()->create([
            'origin' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 10:00', 'UTC')->addMinutes($gapMinutes),
        ]);

        return [$previous, $next];
    }
}
