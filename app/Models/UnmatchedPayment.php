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
        'matched_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'matched_booking_id');
    }

    public function scopeUnmatched($query)
    {
        return $query->whereNull('matched_booking_id');
    }
}