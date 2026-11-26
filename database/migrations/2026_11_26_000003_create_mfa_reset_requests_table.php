<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('action');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['target_user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_reset_requests');
    }
};
