<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seat, boarding pass, gate override and audit row now belong to a coupon (B13b).
 * A connecting passenger has one seat per flight, so passenger_id is no longer unique on
 * seat_assignments and gate_overrides; UNIQUE (flight_id, seat_id) stays (SR-7).
 * Bags travel to the final destination of their direction (SR-67).
 * Columns are nullable here; the backfill fills them and a later migration makes them required.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['seat_assignments', 'gate_overrides'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // A plain index first: MySQL needs one for the passenger_id foreign key.
                $table->index('passenger_id');
            });
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['passenger_id']);
                $table->foreignId('coupon_id')->nullable()->after('passenger_id')->unique()->constrained();
            });
        }

        Schema::table('boarding_passes', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('passenger_id')->constrained();
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('flight_id')->constrained();
        });

        Schema::table('bags', function (Blueprint $table) {
            $table->string('direction', 10)->default('Outbound')->after('passenger_id'); // SegmentDirection
            $table->char('destination', 3)->nullable()->after('direction');
            $table->foreign('destination')->references('code')->on('airports');
        });
    }

    public function down(): void
    {
        Schema::table('bags', function (Blueprint $table) {
            $table->dropForeign(['destination']);
            $table->dropColumn(['direction', 'destination']);
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::table('boarding_passes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        foreach (['seat_assignments', 'gate_overrides'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['coupon_id']);
                $table->dropUnique(['coupon_id']);
                $table->dropColumn('coupon_id');
            });
            Schema::table($name, function (Blueprint $table) {
                $table->unique('passenger_id');
            });
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['passenger_id']);
            });
        }
    }
};
