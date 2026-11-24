<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPaymentHold extends Model
{
    protected $fillable = [
        'payment_reference_id',
        'user_id',
        'given_held',
        'reserved_held',
        'status',
        'held_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'given_held' => 'integer',
            'reserved_held' => 'integer',
            'held_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function paymentReference(): BelongsTo
    {
        return $this->belongsTo(PaymentReference::class, 'payment_reference_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
