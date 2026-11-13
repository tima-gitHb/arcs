<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fares per route (B13b, SR-69). A fare with no origin and no destination is the default; a fare with
 * a route replaces it on that route. FareService falls back to the default.
 * One fare per (origin, destination, class, passenger type). SQL treats NULLs as all different in a
 * UNIQUE key, so the key uses two generated columns where "no airport" is '': the defaults stay unique too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fares', function (Blueprint $table) {
            $table->char('origin_airport_code', 3)->nullable()->after('id');
            $table->char('destination_airport_code', 3)->nullable()->after('origin_airport_code');
            $table->foreign('origin_airport_code')->references('code')->on('airports');
            $table->foreign('destination_airport_code')->references('code')->on('airports');

            $table->string('route_origin', 3)->virtualAs("coalesce(origin_airport_code, '')");
            $table->string('route_destination', 3)->virtualAs("coalesce(destination_airport_code, '')");
        });

        Schema::table('fares', function (Blueprint $table) {
            $table->dropUnique(['cabin_class', 'passenger_type']);
            $table->unique(['route_origin', 'route_destination', 'cabin_class', 'passenger_type'], 'fares_route_class_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fares', function (Blueprint $table) {
            $table->dropUnique('fares_route_class_type_unique');
        });

        // Only the defaults fit the old shape.
        DB::table('fares')->whereNotNull('origin_airport_code')->orWhereNotNull('destination_airport_code')->delete();

        Schema::table('fares', function (Blueprint $table) {
            $table->dropColumn(['route_origin', 'route_destination']);
            $table->dropForeign(['origin_airport_code']);
            $table->dropForeign(['destination_airport_code']);
            $table->dropColumn(['origin_airport_code', 'destination_airport_code']);
            $table->unique(['cabin_class', 'passenger_type']);
        });
    }
};
