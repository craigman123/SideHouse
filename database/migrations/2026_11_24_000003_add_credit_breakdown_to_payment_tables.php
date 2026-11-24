<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reference', function (Blueprint $table) {
            $table->unsignedInteger('given_used')->default(0);
            $table->unsignedInteger('reserved_used')->default(0);
            $table->unsignedInteger('cash_due')->default(0);
            $table->unsignedInteger('cash_paid')->default(0);
            $table->string('credit_status', 16)->default('none');
            $table->timestamp('credit_held_at')->nullable();
            $table->timestamp('credit_released_at')->nullable();
            $table->string('credit_checkout_key', 128)->nullable();
            $table->unique(['credit_checkout_key'], 'payment_reference_credit_checkout_unique');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('given_used')->default(0);
            $table->unsignedInteger('reserved_used')->default(0);
            $table->unsignedInteger('cash_due')->default(0);
            $table->unsignedInteger('cash_paid')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['given_used', 'reserved_used', 'cash_due', 'cash_paid']);
        });
        Schema::table('payment_reference', function (Blueprint $table) {
            $table->dropUnique('payment_reference_credit_checkout_unique');
            $table->dropColumn([
                'given_used', 'reserved_used', 'cash_due', 'cash_paid',
                'credit_status', 'credit_held_at', 'credit_released_at',
                'credit_checkout_key',
            ]);
        });
    }
};
