<?php

namespace App\Http\Controllers\User;

use App\Models\Booking;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class UserDashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $upcomingBookings = Booking::where('user_id', $user->id)
            ->where('date', '>=', today())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $recentBookings = Booking::where('user_id', $user->id)
            ->orderByDesc('date')
            ->limit(10)
            ->get();

        $nextBooking = $upcomingBookings->first();

        return view('user.dashboard', compact(
            'upcomingBookings',
            'recentBookings',
            'nextBooking'
        ));
    }
}