<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

/**
 * Updates network airports from the migration with demo connection times and display names.
 * The rows must already exist (B13a migration); this seeder only patches attributes.
 */
class AirportSeeder extends Seeder
{
    /**
     * @var array<string, array{name?: string, min_connection_minutes: int}>
     */
    private const PATCHES = [
        'AMM' => ['min_connection_minutes' => 45],
        'ATH' => ['min_connection_minutes' => 45],
        'BEY' => ['min_connection_minutes' => 45],
        'CAI' => ['min_connection_minutes' => 60],
        'CDG' => ['name' => 'Paris–Charles de Gaulle', 'min_connection_minutes' => 90],
        'DOH' => ['min_connection_minutes' => 60],
        'DXB' => ['min_connection_minutes' => 75],
        'FCO' => ['min_connection_minutes' => 60],
        'FRA' => ['min_connection_minutes' => 60],
        'IST' => ['min_connection_minutes' => 60],
        'JED' => ['min_connection_minutes' => 75],
        'LHR' => ['min_connection_minutes' => 90],
    ];

    public function run(): void
    {
        foreach (self::PATCHES as $code => $patch) {
            Airport::query()->whereKey($code)->update($patch);
        }
    }
}
