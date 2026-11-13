<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * After the backfill (B13b): coupons hold the status, so passengers lose status, checked_in_at
 * and boarded_at, and every seat, pass and override must name its coupon.
 * down() puts the columns back from each passenger's first coupon.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['seat_assignments', 'boarding_passes', 'gate_overrides'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('coupon_id')->nullable(false)->change();
            });
        }

        Schema::table('bags', function (Blueprint $table) {
            $table->char('destination', 3)->nullable(false)->change();
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn(['status', 'checked_in_at', 'boarded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->string('status', 20)->default('Booked')->after('accompanying_adult_id');
            $table->dateTime('checked_in_at')->nullable()->after('status');
            $table->dateTime('boarded_at')->nullable()->after('checked_in_at');
        });

        // The first coupon of each passenger (lowest segment) is what the passenger row used to hold.
        $first = fn (string $column) => DB::raw("(select coupons.{$column} from coupons where coupons.passenger_id = passengers.id"
            .' order by coupons.reservation_segment_id limit 1)');
        DB::table('passengers')->whereExists(fn ($query) => $query->from('coupons')->whereColumn('coupons.passenger_id', 'passengers.id'))
            ->update([
                'status' => $first('status'),
                'checked_in_at' => $first('checked_in_at'),
                'boarded_at' => $first('boarded_at'),
            ]);

        Schema::table('bags', function (Blueprint $table) {
            $table->char('destination', 3)->nullable()->change();
        });

        foreach (['seat_assignments', 'boarding_passes', 'gate_overrides'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('coupon_id')->nullable()->change();
            });
        }
    }
};
