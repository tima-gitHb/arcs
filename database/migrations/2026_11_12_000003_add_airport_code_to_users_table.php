<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The station (airport) a staff member works at (B13a). Null = no station yet:
 * check-in and gate staff cannot work until a Manager sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('airport_code', 3)->nullable()->after('role');
            $table->foreign('airport_code')->references('code')->on('airports');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['airport_code']);
            $table->dropColumn('airport_code');
        });
    }
};
