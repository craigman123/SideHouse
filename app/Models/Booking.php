<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_name',
        'contact_number',
        'court_id',
        'date',
        'start_time',
        'end_time',
        'amount',
        'payment_method',
        'status',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function equipment()
    {
        return $this->hasMany(BookingEquipment::class);
    }

    public function scopeStatus($query, $status)
    {
        if ($status && $status !== 'all') {
            return $query->where('status', $status);
        }

        return $query;
    }

    public function scopeSearch($query, $term)
    {
        if ($term) {
            return $query->where('customer_name', 'like', '%' . $term . '%');
        }

        return $query;
    }

    public function scopeOnDate($query, $date)
    {
        if ($date) {
            return $query->whereDate('date', $date);
        }

        return $query;
    }
}