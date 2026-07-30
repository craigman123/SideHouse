<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);

            // Percentage off a court's hourly_rate at booking time, e.g. 20 = 20% off.
            $table->unsignedTinyInteger('discount_percent')->default(0);

            // How long one purchase/renewal of this plan lasts.
            $table->unsignedInteger('duration_days')->default(30);

            // Whether admins currently allow new signups to this plan —
            // separate from an individual membership's own status.
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
