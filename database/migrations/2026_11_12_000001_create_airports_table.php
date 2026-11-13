<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The airports of the network (B13a). Flights and staff stations reference airports.code.
 * The rows are inserted here, not in a seeder: flights have a foreign key to this table,
 * so every database (development, tests, production) needs them before the first flight.
 * Later additions go in a new migration or through the Manager's screen (card M13+).
 */
return new class extends Migration
{
    /**
     * IATA code => [name, city, ISO-2 country, IANA time zone].
     */
    private const AIRPORTS = [
        'AMM' => ['Queen Alia International', 'Amman', 'JO', 'Asia/Amman'],
        'ATH' => ['Athens International', 'Athens', 'GR', 'Europe/Athens'],
        'AUH' => ['Zayed International', 'Abu Dhabi', 'AE', 'Asia/Dubai'],
        'BEY' => ['Beirut–Rafic Hariri International', 'Beirut', 'LB', 'Asia/Beirut'],
        'CAI' => ['Cairo International', 'Cairo', 'EG', 'Africa/Cairo'],
        'CDG' => ['Paris Charles de Gaulle', 'Paris', 'FR', 'Europe/Paris'],
        'DOH' => ['Hamad International', 'Doha', 'QA', 'Asia/Qatar'],
        'DXB' => ['Dubai International', 'Dubai', 'AE', 'Asia/Dubai'],
        'FCO' => ['Rome Fiumicino', 'Rome', 'IT', 'Europe/Rome'],
        'FRA' => ['Frankfurt', 'Frankfurt', 'DE', 'Europe/Berlin'],
        'GVA' => ['Geneva', 'Geneva', 'CH', 'Europe/Zurich'],
        'IST' => ['Istanbul Airport', 'Istanbul', 'TR', 'Europe/Istanbul'],
        'JED' => ['King Abdulaziz International', 'Jeddah', 'SA', 'Asia/Riyadh'],
        'JFK' => ['John F. Kennedy International', 'New York', 'US', 'America/New_York'],
        'KWI' => ['Kuwait International', 'Kuwait City', 'KW', 'Asia/Kuwait'],
        'LCA' => ['Larnaca International', 'Larnaca', 'CY', 'Asia/Nicosia'],
        'LHR' => ['London Heathrow', 'London', 'GB', 'Europe/London'],
        'ORY' => ['Paris Orly', 'Paris', 'FR', 'Europe/Paris'],
        'RUH' => ['King Khalid International', 'Riyadh', 'SA', 'Asia/Riyadh'],
    ];

    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->char('code', 3)->primary(); // IATA, e.g. BEY
            $table->string('name', 100);
            $table->string('city', 80);
            $table->char('country', 2); // ISO-2; names come from CountryList
            $table->string('timezone', 64); // IANA name, e.g. Europe/Paris
            $table->unsignedSmallInteger('min_connection_minutes')->default(60);
        });

        $rows = [];
        foreach (self::AIRPORTS as $code => [$name, $city, $country, $timezone]) {
            $rows[] = compact('code', 'name', 'city', 'country', 'timezone');
        }

        DB::table('airports')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
