<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // No separate date column — a booking is picked on a single
            // calendar day (the widget picks date, then hours), so every
            // slot shares booking.date. Time-only range for the hour block.
            $table->time('start_time');
            $table->time('end_time');

            // Snapshot of the court's hourly_rate at booking time, same
            // pattern as booking_equipment.price_each — a later rate
            // change shouldn't rewrite the cost of past bookings.
            $table->decimal('price', 10, 2)->default(0);

            $table->timestamps();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slots');
    }
};