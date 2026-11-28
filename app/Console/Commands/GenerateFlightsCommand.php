<?php

namespace App\Console\Commands;

use App\Models\FlightSchedule;
use App\Services\GenerateFlightsFromSchedule;
use Illuminate\Console\Command;

/**
 * Generate missing dated flights from every active weekly schedule.
 * Safe to re-run: existing flight_no + local date pairs are skipped.
 */
class GenerateFlightsCommand extends Command
{
    protected $signature = 'arcs:generate-flights {--weeks=8 : How many weeks ahead from today}';

    protected $description = 'Create missing Scheduled flights from active weekly schedules';

    public function handle(GenerateFlightsFromSchedule $generator): int
    {
        $weeks = max(1, (int) $this->option('weeks'));
        $from = now()->startOfDay();
        $to = $from->copy()->addWeeks($weeks);

        $schedules = FlightSchedule::query()->where('active', true)->orderBy('flight_no')->get();

        if ($schedules->isEmpty()) {
            $this->warn('No active schedules.');

            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalSkipped = 0;
        $totalClash = 0;

        foreach ($schedules as $schedule) {
            $result = $generator->generate($schedule, $from, $to);
            $totalCreated += $result['created'];
            $totalSkipped += $result['skipped'];
            $totalClash += $result['clashes'];

            $this->line(sprintf(
                '%s %s→%s: created %d · skipped %d · clash %d',
                $schedule->flight_no,
                $schedule->origin,
                $schedule->destination,
                $result['created'],
                $result['skipped'],
                $result['clashes'],
            ));

            foreach ($result['clash_dates'] as $clash) {
                $this->warn("  clash {$clash['date']} with {$clash['with']}");
            }
        }

        $this->info("Done. Created {$totalCreated} · skipped {$totalSkipped} · clash {$totalClash} (range {$from->toDateString()} → {$to->toDateString()}).");

        return self::SUCCESS;
    }
}
