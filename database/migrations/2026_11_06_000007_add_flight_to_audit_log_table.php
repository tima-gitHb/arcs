<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit row can now be about a flight, with no passenger.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('passenger_id')->nullable()->change();
            $table->foreignId('flight_id')->nullable()->after('passenger_id')->constrained();
        });
    }

    /**
     * Rows without a passenger cannot exist in the old shape, so they are removed.
     */
    public function down(): void
    {
        DB::table('audit_log')->whereNull('passenger_id')->delete();

        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flight_id');
            $table->foreignId('passenger_id')->nullable(false)->change();
        });
    }
};
