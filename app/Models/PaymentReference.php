<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per checkout session's payment — a guest paying once via QR Ph
 * for several different booked dates in the same session still gets
 * exactly one row here, with every one of those Booking rows pointing
 * back at it via bookings.payment_reference_id. See the
 * 2026_08_20_000000_restructure_payment_reference_table migration for why
 * this used to be the other way around (booking_id living on this table).
 *
 * confirmed_at is the actual source of truth for "did this get paid" —
 * PaymongoQrPhController's webhook sets it (and cascades 'paid' to every
 * linked booking) once PayMongo confirms the payment.
 */
class PaymentReference extends Model
{
    use HasFactory;

    protected $table = 'payment_reference';

    protected $fillable = [
        'payment_proof_path',
        'gcash_reference_number',
        'payment_reference',
        'payment_method',
        'amount',
        'confirmed_at',
        'payment_intent_id',
        'qr_image_url',
        'qr_code_expires_at',
    ];

    protected $casts = [
        'amount'              => 'decimal:2',
        'confirmed_at'        => 'datetime',
        'qr_code_expires_at'  => 'datetime',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'payment_reference_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}