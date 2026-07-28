<?php

namespace App\Http\Controllers\User;

use App\Models\Booking;
use App\Models\Court;
use Carbon\Carbon;
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

        // $nextBooking->date / start_time / end_time represent local
        // Philippines wall-clock time (e.g. "8:00 PM" really does mean
        // 8:00 PM in Manila), but Carbon::parse() interprets naive
        // strings using config('app.timezone') — which defaults to UTC
        // in a fresh Laravel install. If that config isn't set to
        // Asia/Manila, every ISO timestamp we hand to the browser ends
        // up 8 hours off from the real moment, which is exactly the
        // "8 hours" of extra countdown you were seeing. Building the
        // Carbon instance explicitly in Asia/Manila sidesteps that
        // regardless of what app.timezone happens to be configured as.
        //
        // (If your bookings are actually entered/stored in a different
        // timezone, swap 'Asia/Manila' below for that instead.)
        $bookingTimezone = 'Asia/Manila';

        $nextBookingIso = $nextBooking
            ? Carbon::createFromFormat('Y-m-d', $nextBooking->date->format('Y-m-d'), $bookingTimezone)
                ->setTimeFromTimeString($nextBooking->start_time)
                ->toIso8601String()
            : null;

        $nextBookingEndIso = $nextBooking
            ? Carbon::createFromFormat('Y-m-d', $nextBooking->date->format('Y-m-d'), $bookingTimezone)
                ->setTimeFromTimeString($nextBooking->end_time)
                ->toIso8601String()
            : null;

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
            'nextBookingIso',
            'nextBookingEndIso',
            'courts'
        ));
    }
}