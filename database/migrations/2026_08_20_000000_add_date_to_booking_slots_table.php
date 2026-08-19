<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every slot now carries its own true calendar date, instead of
     * inheriting the parent booking's single `date` column. This is what
     * actually fixes the overnight-rollover bug: GuestBookingController::
     * store() already computes the correct rolled-forward date for a
     * post-midnight tail slot (e.g. business opens 4 PM, a 6 AM slot
     * really belongs to the next calendar day) — it just never used to
     * persist that anywhere. bookings.date/start_time/end_time stay as
     * they are and now serve as a display envelope only ("earliest slot
     * start -> latest slot end"), not the source of truth for exactly
     * when a booking happens. Anything that needs precision (reminders,
     * conflict checks, availability) reads booking_slots.date instead.
     *
     * Backfilled from the parent booking's date for existing rows —
     * correct for every non-overnight booking made before this column
     * existed, and a reasonable best guess for the rare overnight ones
     * (they'd have needed a manual date fix anyway, same as any
     * pre-existing data-correctness bug).
     */
    public function up(): void
    {
        Schema::table('booking_slots', function (Blueprint $table) {
            $table->date('date')->nullable()->after('booking_id');
        });

        DB::statement('
            UPDATE booking_slots
            SET date = bookings.date
            FROM bookings
            WHERE booking_slots.booking_id = bookings.id
        ');

        Schema::table('booking_slots', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
            $table->index(['date']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_slots', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};
