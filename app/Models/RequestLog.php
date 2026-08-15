<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestLog extends Model
{
    /**
     * Insert-only log — there's no updated_at column, so Eloquent is
     * told not to manage it while still auto-stamping created_at.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'method',
        'path',
        'status',
        'ip_address',
        'user_id',
    ];
}
