<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LumiSuggestionDismissal extends Model
{
    protected $fillable = [
        'user_id',
        'suggestion_key',
        'dismissed_until',
    ];

    protected $casts = [
        'dismissed_until' => 'datetime',
    ];
}
