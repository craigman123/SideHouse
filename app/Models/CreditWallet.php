<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditWallet extends Model
{
    protected $fillable = [
        'user_id',
        'given_balance',
        'reserved_balance',
    ];

    protected function casts(): array
    {
        return [
            'given_balance' => 'integer',
            'reserved_balance' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'user_id', 'user_id');
    }
}
