<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualCreditRefund extends Model
{
    protected $fillable = [
        'payment_reference_id',
        'cash_amount',
        'status',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'cash_amount' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentReference(): BelongsTo
    {
        return $this->belongsTo(PaymentReference::class, 'payment_reference_id');
    }
}
