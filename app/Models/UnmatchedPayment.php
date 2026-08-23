<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnmatchedPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_method',
        'amount',
        'reference_number',
        'raw_message',
        'matched_booking_id',
        'matched_payment_reference_id',
        'matched_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    // Historical SMS-matching records only. QR Ph uses PayMongo payment
    // intents, so no new rows should be created after the legacy removal.
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'matched_booking_id');
    }

    public function paymentReference()
    {
        return $this->belongsTo(PaymentReference::class, 'matched_payment_reference_id');
    }

    public function scopeUnmatched($query)
    {
        return $query->whereNull('matched_payment_reference_id');
    }
}
