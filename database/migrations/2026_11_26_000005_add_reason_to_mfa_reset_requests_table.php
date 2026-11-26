<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfa_reset_requests', function (Blueprint $table) {
            $table->string('reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mfa_reset_requests', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
