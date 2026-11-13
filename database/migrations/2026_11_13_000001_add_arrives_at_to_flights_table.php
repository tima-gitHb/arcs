<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a flight lands (B13b), in UTC like every time. Nullable: flights made before B13b have none.
 * A flight without an arrival time cannot be followed by another segment (SR-65).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dateTime('arrives_at')->nullable()->after('departure_at');
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropColumn('arrives_at');
        });
    }
};
