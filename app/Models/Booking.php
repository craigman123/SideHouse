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
        'payment_reference_id',
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

    // Every booking created by store()/storeBooking() now has exactly one
    // shared payment (possibly shared with sibling bookings from the same
    // checkout, if the guest picked several different dates at once). See
    // App\Models\PaymentReference.
    public function paymentReference()
    {
        return $this->belongsTo(PaymentReference::class, 'payment_reference_id');
    }

    // Only present for bookings made through the multi-slot picker.
    // Older/duration-based bookings (e.g. the signed-in user flow) have
    // no rows here — code that reads this must fall back to the
    // booking's own date/start_time/end_time in that case.
    public function slots()
    {
        return $this->hasMany(BookingSlot::class);
    }

    // A booking with no linked payment was never legitimately created by
    // the normal checkout flow (store()/storeBooking() always create the
    // payment_reference row in the same DB transaction as the booking
    // itself, so a committed booking without one shouldn't happen). Used
    // by the bookings:cancel-orphaned-unpaid safeguard command as the
    // definition of "this has no payment attached".
    public function hasPayment(): bool
    {
        return $this->payment_reference_id !== null;
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