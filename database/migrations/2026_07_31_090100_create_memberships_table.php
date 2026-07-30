<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();

            // Matches the plain-column style already used for user_id/court_id
            // on the bookings table (no enforced FK constraint there either).
            $table->unsignedBigInteger('user_id');

            $table->foreignId('membership_plan_id')
                ->constrained('membership_plans')
                ->cascadeOnDelete();

            $table->date('start_date');
            $table->date('expiry_date');

            // A user can have multiple memberships over time (renewals,
            // upgrades, expired history) — only one should be 'active' at
            // once, but that's enforced in the application, not the schema.
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
