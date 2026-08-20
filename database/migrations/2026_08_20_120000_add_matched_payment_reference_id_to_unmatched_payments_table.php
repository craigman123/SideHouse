<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patches unmatched_payments with the matched_payment_reference_id column
 * that 2026_08_19_214556_create_payment_reference.php was supposed to add.
 *
 * That migration had already been recorded as "Ran" (batch 14) by the time
 * the unmatched_payments block was added to its up() method — Laravel
 * tracks migrations by filename only, so editing an already-run migration
 * file never gets replayed against the database. This column simply never
 * got created, causing:
 *
 *   SQLSTATE[42703]: Undefined column: 7 ERROR: column
 *   "matched_payment_reference_id" does not exist
 *
 * Guarded with hasColumn/hasTable checks (same defensive pattern as
 * 2026_08_11_090100_add_reminder_sent_at_to_bookings_table.php) so this is
 * safe to run regardless of exactly how far the original migration got.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_reference')
            && Schema::hasTable('unmatched_payments')
            && ! Schema::hasColumn('unmatched_payments', 'matched_payment_reference_id')
        ) {
            Schema::table('unmatched_payments', function (Blueprint $table) {
                $table->foreignId('matched_payment_reference_id')
                    ->nullable()
                    ->after('matched_booking_id')
                    ->constrained('payment_reference')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('unmatched_payments', 'matched_payment_reference_id')) {
            Schema::table('unmatched_payments', function (Blueprint $table) {
                $table->dropForeign(['matched_payment_reference_id']);
                $table->dropColumn('matched_payment_reference_id');
            });
        }
    }
};
