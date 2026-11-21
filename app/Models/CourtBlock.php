<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtBlock extends Model
{
    protected $fillable = [
        'court_id',
        'type',
        'title',
        'notes',
        'block_date',
        'start_time',
        'end_time',
        'created_by',
    ];

    protected $casts = [
        'block_date' => 'date',
    ];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
