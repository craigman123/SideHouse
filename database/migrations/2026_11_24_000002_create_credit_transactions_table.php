<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 16);
            $table->string('direction', 16);
            $table->unsignedInteger('amount');
            $table->string('reason', 32);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('balance_after');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('created_by')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
