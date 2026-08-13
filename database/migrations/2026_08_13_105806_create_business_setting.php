<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            // close_hour can be <= open_hour (e.g. open 8, close 7) — that's
            // an overnight window, same convention GuestBookingController
            // already used for its OPEN_HOUR/CLOSE_HOUR consts.
            $table->unsignedTinyInteger('open_hour')->default(8);
            $table->unsignedTinyInteger('close_hour')->default(7);
            $table->unsignedSmallInteger('step_minutes')->default(30);
            $table->unsignedTinyInteger('min_duration_hours')->default(1);
            $table->unsignedTinyInteger('max_duration_hours')->default(10);
            $table->timestamps();
        });

        // Seed the single settings row up front with the same defaults the
        // hardcoded consts used, so behavior doesn't change until an admin
        // actually edits something on the new Schedule page.
        DB::table('business_settings')->insert([
            'open_hour' => 8,
            'close_hour' => 7,
            'step_minutes' => 30,
            'min_duration_hours' => 1,
            'max_duration_hours' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};