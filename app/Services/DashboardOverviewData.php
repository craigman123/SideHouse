<?php

namespace App\Services;

use App\Models\Booking;

class DashboardOverviewData
{
    public function forAdmin(): array
    {
        return $this->make(false);
    }

    public function forOwner(): array
    {
        return $this->make(true);
    }

    private function make(bool $excludeCancelled): array
    {
        $totalIncome = Booking::where('status', 'paid')->sum('amount');
        $todayIncome = Booking::where('status', 'paid')->whereDate('created_at', today())->sum('amount');
        $totalBookings = Booking::count();
        $todayBookings = Booking::whereDate('date', today())->count();

        $upcoming = Booking::where('date', '>=', today())
            ->when($excludeCancelled, fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->orderBy('date')->orderBy('start_time')->limit(5)->get();

        return compact('totalIncome', 'todayIncome', 'totalBookings', 'todayBookings') + [
            'upcomingBookings' => $upcoming,
        ];
    }
}
