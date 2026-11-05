<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->string('cabin_class', 20)->nullable()->after('type'); // the booked class
            $table->unsignedSmallInteger('extra_bags')->default(0)->after('cabin_class');
            $table->unsignedSmallInteger('extra_kg')->default(0)->after('extra_bags');
        });

        $this->backfill();
    }

    /**
     * Existing passengers get the class of their seat; infants that of their adult.
     * Passengers without a seat stay null.
     */
    public function backfill(): void
    {
        DB::table('seat_assignments')
            ->join('seats', 'seats.id', '=', 'seat_assignments.seat_id')
            ->select('seat_assignments.id', 'seat_assignments.passenger_id', 'seats.cabin_class')
            ->orderBy('seat_assignments.id')
            ->each(function ($row) {
                DB::table('passengers')->where('id', $row->passenger_id)->update(['cabin_class' => $row->cabin_class]);
            });

        DB::table('passengers as infant')
            ->join('passengers as adult', 'adult.id', '=', 'infant.accompanying_adult_id')
            ->select('infant.id', 'adult.cabin_class')
            ->orderBy('infant.id')
            ->each(function ($row) {
                DB::table('passengers')->where('id', $row->id)->update(['cabin_class' => $row->cabin_class]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn(['cabin_class', 'extra_bags', 'extra_kg']);
        });
    }
};
