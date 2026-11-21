<?php

namespace Tests\Feature;

use App\Enums\SegmentDirection;
use App\Exceptions\InvalidJourney;
use App\Models\Flight;
use App\Models\Reservation;
use App\Services\JourneyService;
use App\Support\Journey;
use App\Support\Limits;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * B14 — multi-city legs on reservation_segments (CR-16).
 */
class B14MultiCityLegsTest extends TestCase
{
    use RefreshDatabase;

    private JourneyService $journeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->seed(SettingSeeder::class);
        $this->journeys = app(JourneyService::class);
    }

    private function flight(string $no, string $from, string $to, string $depart, string $arrive): Flight
    {
        return Flight::factory()->create([
            'flight_no' => $no,
            'origin' => $from,
            'destination' => $to,
            'departure_at' => Carbon::parse($depart, 'UTC'),
            'arrives_at' => Carbon::parse($arrive, 'UTC'),
        ]);
    }

    public function test_B14_migration_backfill_sets_outbound_leg_1_and_return_leg_2(): void
    {
        $out = $this->flight('ME201', 'BEY', 'CDG', '2026-11-15 08:00', '2026-11-15 12:45');
        $back = $this->flight('ME204', 'CDG', 'BEY', '2026-11-18 10:00', '2026-11-18 14:15');
        $reservation = Reservation::factory()->create(['flight_id' => $out->id]);
        $this->journeys->addSegments($reservation, new Journey([$out], [$back]));

        $segments = $reservation->fresh()->segments;
        $this->assertSame(1, $segments->firstWhere('direction', SegmentDirection::Outbound)->leg);
        $this->assertSame(2, $segments->firstWhere('direction', SegmentDirection::Return)->leg);
    }

    public function test_B14_a_three_leg_trip_is_valid(): void
    {
        $a = $this->flight('ME101', 'BEY', 'DXB', '2026-11-15 08:00', '2026-11-15 12:00');
        $b = $this->flight('ME102', 'CAI', 'JED', '2026-11-17 08:00', '2026-11-17 12:00');
        $c = $this->flight('ME103', 'RUH', 'AUH', '2026-11-19 08:00', '2026-11-19 14:00');

        $this->journeys->validate(Journey::multiCity([[$a], [$b], [$c]]));

        $reservation = Reservation::factory()->create(['flight_id' => $a->id]);
        $this->journeys->addSegments($reservation, Journey::multiCity([[$a], [$b], [$c]]));

        $this->assertSame([1, 2, 3], $reservation->fresh()->segments->pluck('leg')->all());
        $this->assertCount(3, $this->journeys->legs($reservation->fresh()));
    }

    public function test_B14_a_gap_under_the_minimum_connection_is_refused(): void
    {
        // IST MCT is 60 minutes.
        $a = $this->flight('ME264', 'BEY', 'IST', '2026-11-15 08:00', '2026-11-15 10:00');
        $b = $this->flight('ME216', 'IST', 'CDG', '2026-11-15 10:30', '2026-11-15 14:00');

        try {
            $this->journeys->validate(new Journey([$a, $b]));
            $this->fail('Expected InvalidJourney for a short connection.');
        } catch (InvalidJourney $e) {
            $this->assertStringContainsString('Connection at IST', $e->getMessage());
        }
    }

    public function test_B14_a_leg_starting_before_the_previous_one_ends_is_refused(): void
    {
        $a = $this->flight('ME101', 'BEY', 'DXB', '2026-11-15 08:00', '2026-11-15 18:00');
        $b = $this->flight('ME102', 'CAI', 'JED', '2026-11-15 12:00', '2026-11-15 16:00');

        try {
            $this->journeys->validate(Journey::multiCity([[$a], [$b]]));
            $this->fail('Expected InvalidJourney when the next leg starts too early.');
        } catch (InvalidJourney $e) {
            $this->assertStringContainsString('before', $e->getMessage());
        }
    }

    public function test_B14_a_seventh_leg_is_refused(): void
    {
        $this->assertSame(6, Limits::maxLegs());

        $legs = [];
        $day = 15;
        $prev = 'BEY';
        foreach (['DXB', 'CAI', 'JED', 'RUH', 'AUH', 'DOH', 'IST'] as $i => $dest) {
            $legs[] = [$this->flight(
                'ME'.(200 + $i),
                $prev,
                $dest,
                sprintf('2026-11-%02d 08:00', $day),
                sprintf('2026-11-%02d 14:00', $day),
            )];
            $prev = $dest;
            $day += 2;
        }

        try {
            $this->journeys->validate(Journey::multiCity($legs));
            $this->fail('Expected InvalidJourney for a 7th leg.');
        } catch (InvalidJourney $e) {
            $this->assertStringContainsString('leg', strtolower($e->getMessage()));
        }
    }

    public function test_B14_a_gap_over_24_hours_counts_as_a_new_leg(): void
    {
        $a = $this->flight('ME264', 'BEY', 'IST', '2026-11-15 08:00', '2026-11-15 10:00');
        $b = $this->flight('ME216', 'IST', 'CDG', '2026-11-17 12:00', '2026-11-17 16:00');

        $this->journeys->validate(new Journey([$a, $b]));

        $reservation = Reservation::factory()->create(['flight_id' => $a->id]);
        $this->journeys->addSegments($reservation, new Journey([$a, $b]));

        $this->assertSame([1, 2], $reservation->fresh()->segments->pluck('leg')->all());
        $this->assertSame(2, $this->journeys->legOf($reservation->fresh()->segments->last()));
    }

    public function test_B14_legOf_and_legs_read_the_segment_leg(): void
    {
        $out = $this->flight('ME201', 'BEY', 'CDG', '2026-11-15 08:00', '2026-11-15 12:45');
        $back = $this->flight('ME204', 'CDG', 'BEY', '2026-11-18 10:00', '2026-11-18 14:15');
        $reservation = Reservation::factory()->create(['flight_id' => $out->id]);
        $this->journeys->addSegments($reservation, new Journey([$out], [$back]));

        $segments = $reservation->fresh()->segments;
        $this->assertSame(1, $this->journeys->legOf($segments[0]));
        $this->assertSame(2, $this->journeys->legOf($segments[1]));
        $this->assertCount(2, $this->journeys->legs($reservation->fresh()));
    }
}
