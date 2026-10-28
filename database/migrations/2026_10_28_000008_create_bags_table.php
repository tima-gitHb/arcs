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
        Schema::create('bags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->constrained();
            $table->string('tag_no')->unique();
            $table->decimal('weight_kg', 4, 1);
            $table->boolean('over_allowance')->default(false);
            $table->dateTime('offloaded_at')->nullable();
            $table->foreignId('offloaded_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bags');
    }
};
