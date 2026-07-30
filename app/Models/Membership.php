<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_plan_id',
        'start_date',
        'expiry_date',
        'status',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'expiry_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /**
     * True if this membership is currently in force — status is 'active'
     * AND today hasn't passed its expiry date yet. A row can be marked
     * 'active' but still be expired if nothing's swept it to 'expired' yet,
     * so both checks matter, not just the status column alone.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expiry_date->gte(Carbon::today());
    }
}
