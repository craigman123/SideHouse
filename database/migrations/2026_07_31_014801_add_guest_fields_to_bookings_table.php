<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('contact_number')->nullable()->after('customer_name');
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->foreign('user_id')
                ->references('user_id')->on('users')
                ->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['contact_number', 'user_id']);
        });
    }
};