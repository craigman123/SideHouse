<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class GuestBookingLookupController extends Controller
{
    public function latest(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $booking = Booking::where('email', $validated['email'])
            ->whereNull('user_id') // guest bookings only — never surface a registered user's info here
            ->latest()
            ->first();

        if (!$booking) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'name'  => $booking->customer_name,
            'phone' => $booking->contact_number,
            'email' => $booking->email,
        ]);
    }
}