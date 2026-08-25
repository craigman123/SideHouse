<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a real database-level guarantee against double-booking the same
 * court/date/start_time, on top of the existing application-level
 * lockForUpdate() checks in GuestBookingController::store() and
 * User_UserController::storeBooking().
 *
 * Why this is needed even though the app already locks correctly: the
 * lockForUpdate() checks are only as good as every code path that
 * writes a booking_slots row remembering to take the lock first. A
 * future endpoint, a raw DB::table() insert, a queued job, or a bug
 * that skips the transaction would silently reintroduce double-booking
 * with nothing to stop it at the database level.
 *
 * TWO PROBLEMS THIS MIGRATION HAS TO SOLVE TOGETHER:
 *
 * 1. booking_slots doesn't store court_id directly — only reachable via
 *    booking_slots.booking_id -> bookings.court_id. A unique constraint
 *    needs court_id on the same row, so this adds and backfills it.
 *
 * 2. A cancelled booking's booking_slots rows are NOT deleted when the
 *    booking is cancelled (see GuestBookingController::cancel() /
 *    cancelAll() — they only update the booking's status). If those
 *    rows stayed in the uniqueness check, a plain unique index would
 *    incorrectly block re-booking a slot after its earlier booking was
 *    cancelled. PostgreSQL partial indexes solve this, but a partial
 *    index's WHERE predicate CANNOT reference another table (no
 *    subquery/join allowed — it must be immutable and self-contained on
 *    the indexed table). So this migration denormalizes an `is_active`
 *    boolean directly onto booking_slots itself, kept in sync with the
 *    parent booking's cancellation status, and partial-indexes on that.
 *
 * IMPORTANT — REQUIRED FOLLOW-UP APPLICATION CHANGE:
 * `is_active` is backfilled once here from each slot's CURRENT parent
 * booking status. It will NOT stay in sync automatically after this
 * migration runs — every place that cancels a booking (cancel(),
 * cancelAll(), User_UserController::cancelBooking(), the webhook's
 * handleExpired(), the expire-unconfirmed scheduled command) must also
 * flip is_active to false on that booking's slots in the same
 * transaction, e.g.:
 *
 *     $booking->slots()->update(['is_active' => false]);
 *
 * placed right next to each existing $locked->update(['status' =>
 * 'cancelled']) call. Until every cancellation path is updated, a slot
 * cancelled after this migration runs will still show as active in the
 * index and will correctly continue blocking re-booking (safe failure
 * direction), but will not free up the slot for others until that
 * follow-up change ships. This migration does not modify controller
 * code — flagging this explicitly so it isn't missed.
 *
 * Assumes PostgreSQL (confirmed elsewhere in the app via
 * regexp_replace(..., 'g') usage in GuestBookingController::search()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_slots', function (Blueprint $table) {
            $table->foreignId('court_id')->nullable()->after('booking_id')
                ->constrained('courts')->cascadeOnDelete();

            // Denormalized from the parent booking's status — see the
            // class docblock above for why this can't just be a live
            // join inside a partial index predicate, and for the
            // required application-code follow-up to keep it in sync.
            $table->boolean('is_active')->default(true)->after('court_id');
        });

        // Backfill both columns from each slot's parent booking in one
        // set-based statement rather than chunked Eloquent — this only
        // needs to run once, and doing it as a single UPDATE avoids
        // loading every row into memory.
        DB::statement(<<<'SQL'
            UPDATE booking_slots
            SET
                court_id = bookings.court_id,
                is_active = (bookings.status != 'cancelled')
            FROM bookings
            WHERE booking_slots.booking_id = bookings.id
        SQL);

        // If any slot's parent booking was itself deleted (orphaned slot
        // row with no matching booking), court_id would still be null
        // here. Surface that loudly now rather than let the NOT NULL
        // step below fail with a less useful error, or silently leave a
        // gap in the constraint.
        $orphanCount = DB::table('booking_slots')->whereNull('court_id')->count();
        if ($orphanCount > 0) {
            throw new \RuntimeException(
                "{$orphanCount} booking_slots row(s) have no matching booking and could not be "
                . 'backfilled with a court_id. Investigate and resolve these before re-running this '
                . 'migration (either delete the orphaned rows or manually set their court_id).'
            );
        }

        Schema::table('booking_slots', function (Blueprint $table) {
            $table->foreignId('court_id')->nullable(false)->change();
        });

        // Valid Postgres partial unique index — the predicate only
        // references booking_slots' own is_active column, no subquery
        // or join, so this is actually enforceable by Postgres (unlike
        // referencing bookings.status directly, which is not allowed).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX booking_slots_court_date_start_unique
            ON booking_slots (court_id, date, start_time)
            WHERE is_active = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS booking_slots_court_date_start_unique');

        Schema::table('booking_slots', function (Blueprint $table) {
            $table->dropForeign(['court_id']);
            $table->dropColumn(['court_id', 'is_active']);
        });
    }
};
