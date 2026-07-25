<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function mainDashboard()
    {
        $totalIncome = Booking::where('status', 'paid')->sum('amount');

        $todayIncome = Booking::where('status', 'paid')
            ->whereDate('created_at', today())
            ->sum('amount');

        $totalBookings = Booking::count();

        $todayBookings = Booking::whereDate('date', today())->count();

        $upcomingBookings = Booking::where('date', '>=', today())
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit(5)
            ->get();

        return view('main_dashboard', compact(
            'totalIncome',
            'todayIncome',
            'totalBookings',
            'todayBookings',
            'upcomingBookings'
        ));
    }
}