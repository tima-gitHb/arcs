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
        Schema::create('fares', function (Blueprint $table) {
            $table->id();
            $table->string('cabin_class', 20);
            $table->string('passenger_type', 10);
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['cabin_class', 'passenger_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fares');
    }
};
