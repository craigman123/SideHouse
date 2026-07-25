<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;

class Admin_DashboardController extends Controller
{
    public function index()
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

        return view('admin.admin_dashboard', compact(
            'totalIncome',
            'todayIncome',
            'totalBookings',
            'todayBookings',
            'upcomingBookings'
        ));
    }
}