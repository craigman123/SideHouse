<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function dashboard()
    {
        $bookings = auth()->user()->bookings()
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return view('user.dashboard', compact('bookings'));
    }

    public function createBooking()
    {
        return view('user.book');
    }

    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'court_id' => 'required|integer|min:1|max:4',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
        ]);

        Booking::create([
            'user_id' => auth()->id(),
            'customer_name' => auth()->user()->name,
            'court_id' => $validated['court_id'],
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'amount' => 500,
            'status' => 'pending',
        ]);

        return redirect()->route('user.dashboard')->with('success', 'Booking submitted! Awaiting confirmation.');
    }
}
