<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing data in the B13b shape, nothing lost (design §b):
 * every reservation becomes a one-segment outbound journey on its flight, every passenger gets one
 * coupon with their status and times, and seats, passes, overrides, audit rows and bags point at it.
 * Plain queries, no models: later model changes cannot break this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $now = now()->format('Y-m-d H:i:s');

            DB::table('reservation_segments')->insertUsing(
                ['reservation_id', 'flight_id', 'sequence', 'direction', 'created_at', 'updated_at'],
                DB::table('reservations')->selectRaw("id, flight_id, 1, 'Outbound', ?, ?", [$now, $now]),
            );

            DB::table('coupons')->insertUsing(
                ['passenger_id', 'reservation_segment_id', 'flight_id', 'status', 'checked_in_at', 'boarded_at', 'created_at', 'updated_at'],
                DB::table('passengers')
                    ->join('reservation_segments', 'reservation_segments.reservation_id', '=', 'passengers.reservation_id')
                    ->selectRaw(
                        'passengers.id, reservation_segments.id, reservation_segments.flight_id, passengers.status, passengers.checked_in_at, passengers.boarded_at, ?, ?',
                        [$now, $now],
                    ),
            );

            $couponOf = fn (string $table) => DB::raw("(select coupons.id from coupons where coupons.passenger_id = {$table}.passenger_id)");

            foreach (['seat_assignments', 'boarding_passes', 'gate_overrides'] as $table) {
                DB::table($table)->update(['coupon_id' => $couponOf($table)]);
            }
            DB::table('audit_log')->whereNotNull('passenger_id')->update(['coupon_id' => $couponOf('audit_log')]);

            DB::table('bags')->update([
                'direction' => 'Outbound',
                'destination' => DB::raw('(select flights.destination from passengers'
                    .' join reservations on reservations.id = passengers.reservation_id'
                    .' join flights on flights.id = reservations.flight_id'
                    .' where passengers.id = bags.passenger_id)'),
            ]);

            $this->ensureNothingLost();
        });
    }

    private function ensureNothingLost(): void
    {
        $checks = [
            'one segment per reservation' => DB::table('reservations')->count() === DB::table('reservation_segments')->count(),
            'one coupon per passenger' => DB::table('passengers')->count() === DB::table('coupons')->count(),
            'every seat has a coupon' => DB::table('seat_assignments')->whereNull('coupon_id')->doesntExist(),
            'every pass has a coupon' => DB::table('boarding_passes')->whereNull('coupon_id')->doesntExist(),
            'every override has a coupon' => DB::table('gate_overrides')->whereNull('coupon_id')->doesntExist(),
            'every bag has a destination' => DB::table('bags')->whereNull('destination')->doesntExist(),
        ];

        foreach ($checks as $check => $ok) {
            if (! $ok) {
                throw new RuntimeException("B13b backfill failed: {$check}.");
            }
        }
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (['seat_assignments', 'boarding_passes', 'gate_overrides', 'audit_log'] as $table) {
                DB::table($table)->update(['coupon_id' => null]);
            }
            DB::table('bags')->update(['destination' => null]);
            DB::table('coupons')->delete();
            DB::table('reservation_segments')->delete();
        });
    }
};
