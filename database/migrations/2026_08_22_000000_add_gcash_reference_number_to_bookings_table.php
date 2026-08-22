<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'gcash_reference_number')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('gcash_reference_number')->nullable()->after('payment_method');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'gcash_reference_number')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('gcash_reference_number');
            });
        }
    }
};
