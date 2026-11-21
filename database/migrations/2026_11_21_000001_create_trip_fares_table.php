<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip fares (B14, CR-13/CR-14): one price for the journey origin → final destination,
 * instead of summing each flight's route fare. round_trip_cents is optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_fares', function (Blueprint $table) {
            $table->id();
            $table->char('origin_code', 3);
            $table->char('destination_code', 3);
            $table->string('cabin_class', 20);
            $table->string('passenger_type', 20);
            $table->unsignedInteger('one_way_cents');
            $table->unsignedInteger('round_trip_cents')->nullable();
            $table->timestamps();

            $table->foreign('origin_code')->references('code')->on('airports');
            $table->foreign('destination_code')->references('code')->on('airports');
            $table->unique(
                ['origin_code', 'destination_code', 'cabin_class', 'passenger_type'],
                'trip_fares_od_cabin_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_fares');
    }
};
