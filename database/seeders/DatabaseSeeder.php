<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with invented sample data (development only).
     * Order matters: flights need the users, the aircraft and its seats, the settings and the entry rules.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            AircraftSeeder::class,
            SettingSeeder::class,
            EntryRuleSeeder::class,
            FareSeeder::class,
            FlightSeeder::class,
            PaymentSeeder::class,
        ]);
    }
}
