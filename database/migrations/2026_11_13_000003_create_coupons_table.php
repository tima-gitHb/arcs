<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One coupon per passenger per segment (B13b): what used to be per passenger is now per flight.
 * status, checked_in_at and boarded_at change only through StatusService (SR-26).
 * flight_id repeats the segment's flight so manifests and seat checks need no join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->constrained();
            $table->foreignId('reservation_segment_id')->constrained();
            $table->foreignId('flight_id')->constrained();
            $table->string('status', 20)->default('Booked'); // PassengerStatus
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('boarded_at')->nullable();
            $table->timestamps();

            $table->unique(['passenger_id', 'reservation_segment_id']);
            $table->index(['flight_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
