<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Model events stay on (B13b): a reservation gets its first segment and every passenger a coupon
     * per segment through them, exactly as in the app.
     *
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
            RouteFareSeeder::class,
            TripFareSeeder::class,
            FlightSeeder::class,
            PaymentSeeder::class,
        ]);
    }
}
