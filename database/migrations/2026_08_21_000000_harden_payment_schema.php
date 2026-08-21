<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'payment_method')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('payment_method')->default('arrival');
            });
        }

        // Existing installations had this column outside of migrations and
        // defaulted it to arrival. Derive the real method from the shared
        // payment record so expiry jobs immediately work for old pending
        // checkouts too.
        if (Schema::hasTable('payment_reference')) {
            DB::table('payment_reference')->orderBy('id')->each(function ($payment) {
                DB::table('bookings')
                    ->where('payment_reference_id', $payment->id)
                    ->where('status', 'pending')
                    ->where(function ($query) {
                        $query->whereNull('payment_method')->orWhere('payment_method', 'arrival');
                    })
                    ->update(['payment_method' => $payment->payment_method]);
            });
        }
    }

    public function down(): void
    {
        // Do not drop a production column that pre-dated this migration.
    }
};
