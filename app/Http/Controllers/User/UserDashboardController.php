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

        // Only active courts should show up here — maintenance/inactive
        // courts are excluded until an admin flips their status back.
        $courts = Court::where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('user.user-dashboard', compact(
            'userName',
            'upcomingBookings',
            'recentBookings',
            'nextBooking',
            'courts'
        ));
    }
}