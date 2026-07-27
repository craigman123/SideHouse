<?php

namespace App\Http\Controllers\User;

use App\Models\Booking;
use App\Models\Court;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class UserDashboardController extends Controller
{
    public function index()
    {
        $userInfo = Auth::user();

        $upcomingBookings = Booking::where('user_id', $userInfo->user_id)
            ->where('date', '>=', today())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $recentBookings = Booking::where('user_id', $userInfo->user_id)
            ->orderByDesc('date')
            ->limit(10)
            ->get();

        $nextBooking = $upcomingBookings->first();
        $userName = $userInfo->name;

        // Adjust the column names below (name/type/price/length/width) to match
        // your actual courts table if they're named differently.
        $courts = Court::orderBy('name')->get();

        return view('user.user-dashboard', compact(
            'userName',
            'upcomingBookings',
            'recentBookings',
            'nextBooking',
            'courts'
        ));
    }
}