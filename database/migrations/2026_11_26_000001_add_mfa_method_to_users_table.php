<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mfa_method')->nullable()->after('mfa_enabled');
        });

        DB::table('users')
            ->whereIn('role', ['admin', 'owner', 'staff'])
            ->where('mfa_enabled', true)
            ->whereNotNull('mfa_secret')
            ->where('mfa_secret', '<>', '')
            ->update(['mfa_method' => 'totp']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mfa_method');
        });
    }
};
