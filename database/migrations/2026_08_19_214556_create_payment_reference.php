<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flips the payment_reference <-> bookings relationship.
     *
     * Before: payment_reference.booking_id -> bookings.id (many payment
     * attempts could belong to one booking — used for reference-number
     * correction retries).
     *
     * After: bookings.payment_reference_id -> payment_reference.id (many
     * bookings can share one payment — a guest who books several
     * different dates in one checkout pays once, and every one of those
     * bookings points at that same payment_reference row).
     *
     * payment_reference also gains `amount` (decimal, replacing the old
     * string `price` for real comparisons) and `confirmed_at` — it is now
     * the source of truth for "did this actually get paid", which the
     * webhook controllers and reminder/expiry logic read instead of
     * per-booking columns.
     */
    public function up(): void
    {
        Schema::table('payment_reference', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('payment_method');
            $table->timestamp('confirmed_at')->nullable()->after('amount');
        });

        // Backfill amount from the old string `price` column for any rows
        // that already exist, before we stop writing to `price` going
        // forward. Harmless no-op if the table is empty.
        DB::table('payment_reference')->whereNotNull('price')->orderBy('id')->each(function ($row) {
            DB::table('payment_reference')
                ->where('id', $row->id)
                ->update(['amount' => is_numeric($row->price) ? $row->price : null]);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('payment_reference_id')
                ->nullable()
                ->after('court_id')
                ->constrained('payment_reference')
                ->nullOnDelete();
        });

        // Best-effort backfill: point each existing booking at whichever
        // payment_reference row already pointed at it under the old
        // direction, before that column goes away.
        DB::statement('
            UPDATE bookings
            SET payment_reference_id = payment_reference.id
            FROM payment_reference
            WHERE payment_reference.booking_id = bookings.id
        ');

        Schema::table('payment_reference', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropColumn('booking_id');
        });

        Schema::table('unmatched_payments', function (Blueprint $table) {
            // Unmatched SMS payments are now claimed onto a shared
            // payment_reference (which can cover several bookings at
            // once), not a single booking directly. matched_booking_id is
            // left in place, unused, for anything historical that still
            // reads it.
            $table->foreignId('matched_payment_reference_id')
                ->nullable()
                ->after('matched_booking_id')
                ->constrained('payment_reference')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unmatched_payments', function (Blueprint $table) {
            $table->dropForeign(['matched_payment_reference_id']);
            $table->dropColumn('matched_payment_reference_id');
        });

        Schema::table('payment_reference', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::statement('
            UPDATE payment_reference
            SET booking_id = bookings.id
            FROM bookings
            WHERE bookings.payment_reference_id = payment_reference.id
        ');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['payment_reference_id']);
            $table->dropColumn('payment_reference_id');
        });

        Schema::table('payment_reference', function (Blueprint $table) {
            $table->dropColumn(['amount', 'confirmed_at']);
        });
    }
};