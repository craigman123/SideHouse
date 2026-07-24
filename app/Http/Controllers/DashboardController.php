<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function mainDashboard()
    {
        // TODO: Replace with real queries once your Booking model/migration exists
        // Example once you have a `bookings` table with columns like:
        // court_id, customer_name, date, start_time, end_time, amount, status

        $totalIncome = DB::table('bookings')->where('status', 'paid')->sum('amount') ?? 0;
        $todayIncome = DB::table('bookings')
            ->whereDate('created_at', today())
            ->where('status', 'paid')
            ->sum('amount') ?? 0;

        $totalBookings = DB::table('bookings')->count();
        $todayBookings = DB::table('bookings')->whereDate('date', today())->count();
        $upcomingBookings = DB::table('bookings')
            ->where('date', '>=', today())
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