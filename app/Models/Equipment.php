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
     * whose reserved hours overlap the one being asked about.
     *
     * A booking's equipment is tied up for exactly the hours it selected
     * (its booking_slots rows), not its full start-to-end envelope — a
     * booking spanning 4–5 PM and 9–10 PM shouldn't tie up equipment for
     * the empty 5–9 PM gap in between. Older duration-only bookings (no
     * slot rows — e.g. the signed-in user flow) fall back to their own
     * start_time/end_time envelope, same as before.
     */
    public function availableStock(string $date, string $startTime, string $endTime): int
    {
        $slotReserved = $this->rentals()
            ->whereHas('booking', function ($q) use ($date) {
                $q->where('date', $date)->where('status', '!=', 'cancelled');
            })
            ->whereHas('booking.slots', function ($q) use ($startTime, $endTime) {
                $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            })
            ->sum('quantity');

        $legacyReserved = $this->rentals()
            ->whereHas('booking', function ($q) use ($date, $startTime, $endTime) {
                $q->where('date', $date)
                    ->where('status', '!=', 'cancelled')
                    ->whereDoesntHave('slots')
                    ->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            })
            ->sum('quantity');

        return max(0, $this->stock_total - $slotReserved - $legacyReserved);
    }
}