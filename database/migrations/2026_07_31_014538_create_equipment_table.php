<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('category', ['padel', 'pickleball'])->default('pickleball');

            // Flat fee per item, regardless of how long it's rented for.
            $table->decimal('price', 10, 2)->default(0);

            $table->unsignedInteger('stock_total')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};