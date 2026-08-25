<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A PayMongo QR Ph payment intent belongs to the whole checkout — its
     * amount is the PaymentReference total, and the webhook confirms every
     * sibling Booking under that PaymentReference, not just one date. So
     * the intent id (and the QR image/expiry generated for it) live here,
     * not on an individual Booking row.
     */
    public function up(): void
    {
        Schema::table('payment_reference', function (Blueprint $table) {
            $table->string('payment_intent_id')->nullable()->unique()->after('id');
            $table->text('qr_image_url')->nullable()->after('payment_intent_id');
            $table->timestamp('qr_code_expires_at')->nullable()->after('qr_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('payment_reference', function (Blueprint $table) {
            $table->dropColumn(['payment_intent_id', 'qr_image_url', 'qr_code_expires_at']);
        });
    }
};
