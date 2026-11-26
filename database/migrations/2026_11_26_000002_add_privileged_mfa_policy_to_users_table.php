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
            $table->boolean('mfa_reenroll_required')->nullable();
            $table->timestamp('mfa_admin_disabled_until')->nullable();
            $table->string('mfa_allowed_methods')->nullable();
            $table->string('mfa_pending_allowed_methods')->nullable();
            $table->unsignedBigInteger('mfa_last_admin_action_step')->nullable();
        });

        DB::table('users')
            ->whereIn('role', ['admin', 'owner', 'staff'])
            ->update(['mfa_allowed_methods' => 'totp']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_reenroll_required',
                'mfa_admin_disabled_until',
                'mfa_allowed_methods',
                'mfa_pending_allowed_methods',
                'mfa_last_admin_action_step',
            ]);
        });
    }
};
