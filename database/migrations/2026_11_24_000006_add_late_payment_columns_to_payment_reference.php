<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reference', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_reference', 'matched_credit_user_id')) {
                $table->unsignedBigInteger('matched_credit_user_id')->nullable()->index();
                $table->foreign('matched_credit_user_id')
                    ->references('user_id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payment_reference', 'late_payment_processed_at')) {
                $table->timestamp('late_payment_processed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_reference', function (Blueprint $table) {
            if (Schema::hasColumn('payment_reference', 'matched_credit_user_id')) {
                $table->dropForeign(['matched_credit_user_id']);
                $table->dropColumn('matched_credit_user_id');
            }
            if (Schema::hasColumn('payment_reference', 'late_payment_processed_at')) {
                $table->dropColumn('late_payment_processed_at');
            }
        });
    }
};