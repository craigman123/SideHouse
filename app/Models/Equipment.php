<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Equipment extends Model
{
    use HasFactory;

    protected $table = 'equipment';

    protected $fillable = [
        'name',
        'category',
        'price',
        'stock_total',
        'status',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'stock_total' => 'integer',
    ];

    public function rentals()
    {
        return $this->hasMany(BookingEquipment::class);
    }

    /**
     * How many of this item are still free for a given date + time window:
     * stock_total minus whatever's already rented by non-cancelled bookings
     * whose time range overlaps the one being asked about. Same overlap
     * logic as court-conflict checking, just summed by quantity instead of
     * a plain exists() check.
     */
    public function availableStock(string $date, string $startTime, string $endTime): int
    {
        $reserved = $this->rentals()
            ->whereHas('booking', function ($q) use ($date, $startTime, $endTime) {
                $q->where('date', $date)
                    ->where('status', '!=', 'cancelled')
                    ->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            })
            ->sum('quantity');

        return max(0, $this->stock_total - $reserved);
    }
}