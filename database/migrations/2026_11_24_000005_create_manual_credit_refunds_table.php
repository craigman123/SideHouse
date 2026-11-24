<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_reference_id')->nullable()->index();
            $table->string('late_payment_key', 128)->nullable();
            $table->unique(['late_payment_key'], 'credit_late_payment_key_unique');
            $table->foreign('payment_reference_id')
                ->references('id')
                ->on('payment_reference')
                ->nullOnDelete();
        });

        Schema::create('manual_credit_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_reference_id');
            $table->unsignedInteger('cash_amount');
            $table->string('status', 16)->default('needs_manual_refund');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->foreign('payment_reference_id')
                ->references('id')
                ->on('payment_reference')
                ->cascadeOnDelete();
            $table->unique(['payment_reference_id'], 'manual_refund_payment_unique');
        });

    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropUnique('credit_late_payment_key_unique');
            $table->dropForeign(['payment_reference_id']);
            $table->dropColumn(['payment_reference_id', 'late_payment_key']);
        });
        Schema::dropIfExists('manual_credit_refunds');
    }
};
