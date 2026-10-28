<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->string('flight_no', 10);
            $table->foreignId('aircraft_id')->constrained('aircraft');
            $table->dateTime('departure_at');
            $table->string('gate', 10)->nullable();
            $table->string('status', 20)->default('Scheduled');
            $table->dateTime('checkin_opens_at');
            $table->dateTime('checkin_closes_at');
            $table->dateTime('boarding_closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
