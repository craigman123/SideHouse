<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->unsignedInteger('players')->default(1);
            $table->date('expiry_date')->nullable();
        });

        DB::table('membership_plans')->update([
            'discount_value' => DB::raw('discount_percent'),
        ]);
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'players', 'expiry_date']);
        });
    }
};
