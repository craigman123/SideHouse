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
        'email',
        'court_id',
        'date',
        'start_time',
        'end_time',
        'amount',
        'payment_method',
        'gcash_reference_number',
        'poll_token',
        'expires_at',
        'confirmed_at',
        'reminder_sent_at',
        'status',
    ];

    protected $casts = [
        'date'         => 'date',
        'amount'       => 'decimal:2',
        'expires_at'   => 'datetime',
        'confirmed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
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

    // Only present for bookings made through the multi-slot picker.
    // Older/duration-based bookings (e.g. the signed-in user flow) have
    // no rows here — code that reads this must fall back to the
    // booking's own date/start_time/end_time in that case.
    public function slots()
    {
        return $this->hasMany(BookingSlot::class);
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