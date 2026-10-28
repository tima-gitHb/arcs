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
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('type', 10)->default('Adult');
            $table->date('date_of_birth')->nullable();
            $table->foreignId('accompanying_adult_id')->nullable()->constrained('passengers');
            $table->string('status', 20)->default('Booked');
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('boarded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};
