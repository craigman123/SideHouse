<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MfaResetRequest extends Model
{
    protected $fillable = [
        'target_user_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'action',
        'status',
        'expires_at',
        'approved_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id', 'user_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'user_id');
    }
}
