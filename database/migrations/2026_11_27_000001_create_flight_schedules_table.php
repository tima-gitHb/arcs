<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly flight patterns (Sprint 4 Feature 1). Generated dated flights point back via flights.flight_schedule_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('flight_no', 10);
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->foreignId('aircraft_id')->constrained('aircraft');
            // ISO weekdays 1=Mon … 7=Sun as JSON array of ints.
            $table->json('days_of_week');
            // Clock time at the origin airport (no timezone); combined with each local date when generating.
            $table->time('departure_local_time');
            $table->unsignedSmallInteger('block_minutes');
            $table->string('gate', 5)->nullable();
            $table->unsignedSmallInteger('checkin_opens_minutes_before')->default(180);
            $table->unsignedSmallInteger('checkin_closes_minutes_before')->default(45);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('origin')->references('code')->on('airports');
            $table->foreign('destination')->references('code')->on('airports');
            $table->index(['flight_no', 'active']);
        });

        Schema::table('flights', function (Blueprint $table) {
            $table->foreignId('flight_schedule_id')->nullable()->after('aircraft_id')
                ->constrained('flight_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flight_schedule_id');
        });
        Schema::dropIfExists('flight_schedules');
    }
};
