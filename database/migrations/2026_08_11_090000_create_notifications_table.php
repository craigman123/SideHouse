<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // One row per recipient — an announcement broadcast to 50
            // users inserts 50 rows. Costs a bit of storage but keeps
            // read/unread trivial to query ("where user_id = ? and
            // read_at is null") instead of needing a separate pivot
            // table just to track per-user read state.
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();

            // 'booking_status' | 'booking_reminder' | 'announcement' —
            // kept as a plain string rather than a DB enum so adding a
            // new type later is a code change, not another migration
            // (see the Postgres enum-migration friction noted elsewhere
            // in this app's history).
            $table->string('type');

            $table->string('title');
            $table->text('body');

            // Optional structured payload — e.g. {"booking_id": 42} so
            // the notifications page can link straight to the booking
            // that triggered it. Nullable for announcements, which have
            // nothing to link to.
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
