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
        Schema::create('baggage_allowances', function (Blueprint $table) {
            $table->id();
            $table->string('cabin_class', 20)->unique();
            $table->unsignedTinyInteger('pieces');
            $table->decimal('max_kg_per_bag', 4, 1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('baggage_allowances');
    }
};
