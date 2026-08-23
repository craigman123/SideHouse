<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PaymongoQrPhController::createQr() writes the PayMongo payment
     * intent id here right after a successful QR generation, so the
     * webhook can look the booking up by an exact id match instead of
     * any fuzzy/heuristic matching. Without this column, that update()
     * call throws an uncaught QueryException — that's what was causing
     * the bare 500 on /guest-book/payment/qrph (every PayMongo API call
     * before it already had explicit ->failed() handling; this line
     * didn't).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_intent_id')->nullable()->after('status');
            // Indexed (not unique) — nullable columns with a unique index
            // behave fine in Postgres (multiple NULLs are allowed), but
            // unique isn't enforced here in case a booking's intent ever
            // needs to be regenerated (e.g. "Try again" creating a fresh
            // intent for the same booking before the first one expires).
            $table->index('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['payment_intent_id']);
            $table->dropColumn('payment_intent_id');
        });
    }
};
