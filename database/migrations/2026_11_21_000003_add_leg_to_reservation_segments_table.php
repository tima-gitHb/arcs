<?php

use App\Enums\SegmentDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-city legs (B14, CR-16). direction stays for one-way / round trip; leg numbers the
 * multi-city groups. Backfill: outbound → 1, return → 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_segments', function (Blueprint $table) {
            $table->unsignedTinyInteger('leg')->default(1)->after('direction');
        });

        DB::table('reservation_segments')
            ->where('direction', SegmentDirection::Outbound->value)
            ->update(['leg' => 1]);

        DB::table('reservation_segments')
            ->where('direction', SegmentDirection::Return->value)
            ->update(['leg' => 2]);
    }

    public function down(): void
    {
        Schema::table('reservation_segments', function (Blueprint $table) {
            $table->dropColumn('leg');
        });
    }
};
