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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->after('password');
            $table->unsignedTinyInteger('failed_logins')->default(0)->after('role');
            $table->boolean('locked')->default(false)->after('failed_logins');
            $table->boolean('active')->default(true)->after('locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'failed_logins', 'locked', 'active']);
        });
    }
};
