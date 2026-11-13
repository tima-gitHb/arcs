<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flights of a reservation, in order (B13b, SR-65): sequence 1, 2, 3… across the whole journey,
 * each in the Outbound or Return direction. Rules live in JourneyService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained();
            $table->foreignId('flight_id')->constrained();
            $table->unsignedTinyInteger('sequence');
            $table->string('direction', 10)->default('Outbound'); // SegmentDirection
            $table->timestamps();

            $table->unique(['reservation_id', 'sequence']);
            $table->unique(['reservation_id', 'flight_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_segments');
    }
};
