<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_payment_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_reference_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedInteger('given_held');
            $table->unsignedInteger('reserved_held');
            $table->string('status', 16)->default('held');
            $table->timestamp('held_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('payment_reference_id')
                ->references('id')
                ->on('payment_reference')
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_payment_holds');
    }
};
