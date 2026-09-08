<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecificDateTimeClosure extends Model
{
    protected $table = 'specific_date_time_closed'; 

    protected $fillable = [
        'date',
        'time_closing',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Only show closures for today or later — matches CourtClosure's
     * upcoming() so both closure lists behave the same way.
     */
    public function scopeUpcoming($query)
    {
        return $query->whereDate('date', '>=', now()->toDateString());
    }

    public static function getSpecificDateTimeClosures()
    {
        return self::all();
    }
}