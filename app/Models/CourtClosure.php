<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourtClosure extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'date',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Closures that apply to a given court on a given date — either one
     * scoped to that exact court, or a store-wide one (court_id null).
     */
    public function scopeForCourtAndDate($query, int $courtId, string $date)
    {
        return $query->whereDate('date', $date)
            ->where(function ($q) use ($courtId) {
                $q->whereNull('court_id')->orWhere('court_id', $courtId);
            });
    }

    public function scopeUpcoming($query)
    {
        return $query->whereDate('date', '>=', today());
    }
}