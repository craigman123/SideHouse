<?php

namespace App\Services;

use App\Models\Booking;

class DashboardOverviewData
{
    public function forAdmin(): array
    {
        return $this->make();
    }

    public function forOwner(): array
    {
        return $this->make();
    }

    private function make(): array
    {
        $totalIncome = Booking::where('status', 'paid')->sum('amount');
        $todayIncome = Booking::where('status', 'paid')->whereDate('created_at', today())->sum('amount');
        $totalBookings = Booking::count();
        $todayBookings = Booking::whereDate('date', today())
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->count();

        $upcoming = Booking::where('date', '>=', today()->toDateString())
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->orderBy('date')->orderBy('start_time')->limit(5)->get();

        return compact('totalIncome', 'todayIncome', 'totalBookings', 'todayBookings') + [
            'upcomingBookings' => $upcoming,
        ];
    }
}
