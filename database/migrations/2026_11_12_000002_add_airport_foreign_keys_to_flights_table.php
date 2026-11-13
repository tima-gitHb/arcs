<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flight flies between two airports of the network (B13a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->foreign('origin')->references('code')->on('airports');
            $table->foreign('destination')->references('code')->on('airports');
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropForeign(['origin']);
            $table->dropForeign(['destination']);
        });
    }
};
