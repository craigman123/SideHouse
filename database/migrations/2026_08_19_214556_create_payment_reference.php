<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_reference')) {
            Schema::create('payment_reference', function (Blueprint $table) {
                $table->id();
                $table->string('payment_proof_path')->nullable();
                $table->string('gcash_reference_number')->nullable();
                $table->string('payment_reference');
                $table->string('payment_method');
                $table->decimal('amount', 10, 2);
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->index(['payment_method', 'amount']);
            });
        }

        if (! Schema::hasColumn('bookings', 'payment_reference_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->foreignId('payment_reference_id')
                    ->nullable()
                    ->constrained('payment_reference')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('unmatched_payments')
            && ! Schema::hasColumn('unmatched_payments', 'matched_payment_reference_id')) {
            Schema::table('unmatched_payments', function (Blueprint $table) {
                $table->foreignId('matched_payment_reference_id')
                    ->nullable()
                    ->constrained('payment_reference')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'payment_reference_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payment_reference_id');
            });
        }

        Schema::dropIfExists('payment_reference');
    }
};
