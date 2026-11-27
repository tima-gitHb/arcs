<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 Feature 2 — refunds: link to the original charge and store the booking fare breakdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('refund_of_payment_id')->nullable()->after('passenger_id')
                ->constrained('payments')->nullOnDelete();
            // Per-passenger / per-flight amounts at booking (cents). Used for partial refunds.
            $table->json('breakdown')->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_payment_id');
            $table->dropColumn('breakdown');
        });
    }
};
