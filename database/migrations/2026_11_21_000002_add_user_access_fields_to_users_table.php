<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->unsignedInteger('access_version')->default(1)->after('permissions');
            $table->foreignId('promoted_by')->nullable()->after('access_version')
                ->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable()->after('promoted_by');
            $table->timestamp('last_login_at')->nullable()->after('promoted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['promoted_by']);
            $table->dropColumn(['permissions', 'access_version', 'promoted_by', 'promoted_at', 'last_login_at']);
        });
    }
};
