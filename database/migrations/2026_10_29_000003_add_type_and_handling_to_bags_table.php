<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bags', function (Blueprint $table) {
            // SR-43: values from App\Enums\BagType and BagHandling
            $table->string('type', 20)->default('Standard')->after('tag_no');
            $table->string('handling', 20)->default('Hold')->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bags', function (Blueprint $table) {
            $table->dropColumn(['type', 'handling']);
        });
    }
};
