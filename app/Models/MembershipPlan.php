<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'discount_type',
        'discount_value',
        'discount_percent',
        'players',
        'expiry_date',
        'duration_days',
        'status',
    ];

    protected $casts = [
        'price'            => 'decimal:2',
        'discount_value'   => 'decimal:2',
        'discount_percent' => 'integer',
        'players'          => 'integer',
        'expiry_date'      => 'date',
        'duration_days'    => 'integer',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }
}
