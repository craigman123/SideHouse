<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Court extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'width',
        'length',
        'surface_type',
        'hourly_rate',
        'status',
        'notes',
    ];

    protected $casts = [
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
    ];

    /**
     * Court area in square meters (width x length).
     */
    public function getAreaAttribute(): float
    {
        return round(((float) $this->width) * ((float) $this->length), 2);
    }
}
