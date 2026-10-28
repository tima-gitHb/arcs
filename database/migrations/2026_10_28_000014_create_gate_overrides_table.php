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
        Schema::create('gate_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->unique()->constrained();
            $table->foreignId('approved_by')->constrained('users');
            $table->text('reason');
            $table->dateTime('approved_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_overrides');
    }
};
