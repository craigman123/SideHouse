<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyCourtClosure extends Model
{
    protected $fillable = ['weekday', 'court_id', 'start_time', 'end_time', 'reason', 'created_by', 'active'];

    protected $casts = ['weekday' => 'integer', 'active' => 'boolean'];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
