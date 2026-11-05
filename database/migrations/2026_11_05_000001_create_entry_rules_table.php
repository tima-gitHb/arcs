<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-only entry rules from the Passport Index dataset (SR-49).
     * Loaded by arcs:import-entry-rules; nobody edits them in the app.
     */
    public function up(): void
    {
        Schema::create('entry_rules', function (Blueprint $table) {
            $table->id();
            $table->char('passport_country', 2);
            $table->char('destination_country', 2);
            $table->string('requirement', 20); // e.g. "visa required", "e-visa", or a number of days

            $table->unique(['passport_country', 'destination_country']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entry_rules');
    }
};
