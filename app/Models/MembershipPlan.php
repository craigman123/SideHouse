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
        'discount_percent',
        'duration_days',
        'status',
    ];

    protected $casts = [
        'price'            => 'decimal:2',
        'discount_percent' => 'integer',
        'duration_days'    => 'integer',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }
}
