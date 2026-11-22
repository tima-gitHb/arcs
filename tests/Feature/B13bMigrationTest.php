<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B13b — existing data moves to segments and coupons without losing anything.
 * Rolls the B13b migrations back, writes rows in the old shape, migrates again.
 * Real migrations (not a transaction): MySQL commits on every schema change.
 */
class B13bMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Rolled back, newest first: four B14 migrations, add_route_to_fares, then the five B13b
     * migrations after add_arrives_at_to_flights_table.
     */
    private const B13B_STEPS = 10;

    public function test_existing_data_becomes_one_segment_and_one_coupon_per_passenger(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::B13B_STEPS]);
        $this->assertFalse(Schema::hasTable('coupons'));
        $this->assertTrue(Schema::hasColumn('passengers', 'status'));

        $agent = User::factory()->create(['role' => 'checkin']);
        $flight = Flight::factory()->create(['origin' => 'BEY', 'destination' => 'CDG']);
        $seatId = DB::table('seats')->insertGetId(['aircraft_id' => $flight->aircraft_id, 'seat_no' => '12A', 'cabin_class' => 'Economy']);
        $reservationId = DB::table('reservations')->insertGetId(['pnr' => 'K7M2NP', 'flight_id' => $flight->id, 'created_by' => $agent->id]);

        $boarded = DB::table('passengers')->insertGetId([
            'reservation_id' => $reservationId, 'first_name' => 'Lea', 'last_name' => 'Morel', 'type' => 'Adult',
            'status' => 'Boarded', 'checked_in_at' => '2026-11-15 05:30:00', 'boarded_at' => '2026-11-15 07:10:00',
        ]);
        $booked = DB::table('passengers')->insertGetId([
            'reservation_id' => $reservationId, 'first_name' => 'Sami', 'last_name' => 'Morel', 'type' => 'Adult',
        ]);
        DB::table('seat_assignments')->insert(['flight_id' => $flight->id, 'seat_id' => $seatId, 'passenger_id' => $boarded]);
        DB::table('boarding_passes')->insert(['passenger_id' => $boarded, 'barcode' => 'K7M2NP-'.$boarded.'-1', 'issued_at' => '2026-11-15 05:30:00']);
        DB::table('gate_overrides')->insert(['passenger_id' => $boarded, 'approved_by' => $agent->id, 'reason' => 'Late', 'approved_at' => '2026-11-15 06:00:00']);
        DB::table('bags')->insert(['passenger_id' => $boarded, 'tag_no' => $this->flightNo('000001'), 'weight_kg' => 20, 'type' => 'Standard', 'handling' => 'Hold']);
        DB::table('audit_log')->insert(['passenger_id' => $boarded, 'field' => 'status', 'old_value' => 'Booked', 'new_value' => 'CheckedIn', 'user_id' => $agent->id, 'changed_at' => '2026-11-15 05:30:00']);
        DB::table('audit_log')->insert(['flight_id' => $flight->id, 'field' => 'gate', 'old_value' => 'A1', 'new_value' => 'A2', 'user_id' => $agent->id, 'changed_at' => '2026-11-15 05:00:00']);

        Artisan::call('migrate');

        // One outbound segment per reservation, one coupon per passenger, statuses and times kept.
        $segment = DB::table('reservation_segments')->sole();
        $this->assertSame([$reservationId, $flight->id, 1, 'Outbound'], [(int) $segment->reservation_id, (int) $segment->flight_id, (int) $segment->sequence, $segment->direction]);

        $coupons = DB::table('coupons')->orderBy('passenger_id')->get()->keyBy('passenger_id');
        $this->assertCount(2, $coupons);
        $this->assertSame('Boarded', $coupons[$boarded]->status);
        $this->assertStringStartsWith('2026-11-15 05:30:00', $coupons[$boarded]->checked_in_at);
        $this->assertStringStartsWith('2026-11-15 07:10:00', $coupons[$boarded]->boarded_at);
        $this->assertSame('Booked', $coupons[$booked]->status);
        $this->assertNull($coupons[$booked]->checked_in_at);
        $this->assertSame($flight->id, (int) $coupons[$boarded]->flight_id);

        // The per-flight records point at the coupon; the bag goes to the reservation's destination.
        $coupon = (int) $coupons[$boarded]->id;
        $this->assertSame($coupon, (int) DB::table('seat_assignments')->value('coupon_id'));
        $this->assertSame($coupon, (int) DB::table('boarding_passes')->value('coupon_id'));
        $this->assertSame($coupon, (int) DB::table('gate_overrides')->value('coupon_id'));
        $this->assertSame(['Outbound', 'CDG'], [DB::table('bags')->value('direction'), DB::table('bags')->value('destination')]);
        $this->assertSame($coupon, (int) DB::table('audit_log')->whereNotNull('passenger_id')->value('coupon_id'));
        $this->assertNull(DB::table('audit_log')->whereNull('passenger_id')->value('coupon_id'));

        // The status columns are gone from passengers.
        $this->assertFalse(Schema::hasColumn('passengers', 'status'));
        $this->assertFalse(Schema::hasColumn('passengers', 'checked_in_at'));
        $this->assertFalse(Schema::hasColumn('passengers', 'boarded_at'));
    }

    public function test_the_migrations_roll_back_to_the_old_shape(): void
    {
        $flight = Flight::factory()->create();
        DB::table('reservations')->insert(['pnr' => 'K7M2NP', 'flight_id' => $flight->id, 'created_by' => User::factory()->create()->id]);
        // A passenger created through the new shape: its coupon holds the status.
        $reservationId = DB::table('reservations')->value('id');
        $segmentId = DB::table('reservation_segments')->value('id') ?? DB::table('reservation_segments')->insertGetId([
            'reservation_id' => $reservationId, 'flight_id' => $flight->id, 'sequence' => 1, 'direction' => 'Outbound',
        ]);
        $passengerId = DB::table('passengers')->insertGetId(['reservation_id' => $reservationId, 'first_name' => 'Lea', 'last_name' => 'Morel', 'type' => 'Adult']);
        DB::table('coupons')->insert(['passenger_id' => $passengerId, 'reservation_segment_id' => $segmentId, 'flight_id' => $flight->id, 'status' => 'CheckedIn', 'checked_in_at' => '2026-11-15 05:30:00']);

        Artisan::call('migrate:rollback', ['--step' => self::B13B_STEPS]);

        $this->assertSame('CheckedIn', DB::table('passengers')->where('id', $passengerId)->value('status'));
        $this->assertFalse(Schema::hasTable('coupons'));
        $this->assertFalse(Schema::hasTable('reservation_segments'));
        $this->assertInstanceOf(Aircraft::class, $flight->aircraft);
    }
}
