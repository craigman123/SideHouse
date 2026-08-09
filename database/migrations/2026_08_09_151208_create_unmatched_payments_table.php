<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unmatched_payments', function (Blueprint $table) {
            $table->id();
            $table->enum('payment_method', ['gcash', 'landbank']);
            $table->decimal('amount', 10, 2);
            $table->string('reference_number')->nullable();

            // Kept for manual reconciliation if something needs a human
            // to sort it out later (e.g. it never gets matched at all).
            $table->text('raw_message');

            // Null until a booking claims this payment. Left in place
            // (not deleted) once matched, as an audit trail of which
            // booking a given SMS ended up resolving.
            $table->foreignId('matched_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();

            $table->index(['payment_method', 'amount', 'matched_booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unmatched_payments');
    }
};