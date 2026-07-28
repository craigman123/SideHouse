<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use Carbon\Carbon;
use Illuminate\Http\Request;

class User_UserController extends Controller
{
    private const OPEN_HOUR = 6;  
    private const CLOSE_HOUR = 22;

    public function dashboard()
    {
        $bookings = auth()->user()->bookings()
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return view('user.dashboard', [
            'bookings' => $bookings,
            'userName' => auth()->user()->name,
        ]);
    }

    public function createBooking()
    {
        $courts = Court::where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('user.bookings.book', [
            'courts'   => $courts,
            'userName' => auth()->user()->name,
        ]);
    }

    /**
     * Return existing (non-cancelled) bookings for a court on a given date,
     * so the front end can grey out already-taken time slots.
     */
    public function availability(Request $request)
    {
        $validated = $request->validate([
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'date'     => ['required', 'date'],
        ]);

        $bookings = Booking::where('court_id', $validated['court_id'])
            ->where('date', $validated['date'])
            ->where('status', '!=', 'cancelled')
            ->get(['start_time', 'end_time']);

        return response()->json([
            'booked' => $bookings->map(fn ($b) => [
                'start' => substr($b->start_time, 0, 5),
                'end'   => substr($b->end_time, 0, 5),
            ]),
        ]);
    }

    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'court_id'   => ['required', 'integer', 'exists:courts,id'],
            'date'       => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration'   => ['required', 'numeric', 'in:1,1.5,2,3'],
        ]);

        $court = Court::findOrFail($validated['court_id']);

        $start = Carbon::parse($validated['date'] . ' ' . $validated['start_time']);
        $end   = $start->copy()->addMinutes((int) round($validated['duration'] * 60));

        $withinHours = (int) $start->format('H') >= self::OPEN_HOUR
            && $end->lte($start->copy()->setTime(self::CLOSE_HOUR, 0));

        if (! $withinHours) {
            return $this->bookingFailed($request, 'That time falls outside operating hours.');
        }

        $conflict = Booking::where('court_id', $court->id)
            ->where('date', $validated['date'])
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($start, $end) {
                $q->where('start_time', '<', $end->format('H:i:s'))
                  ->where('end_time', '>', $start->format('H:i:s'));
            })
            ->exists();

        if ($conflict) {
            return $this->bookingFailed($request, 'That slot was just taken. Please pick another time.');
        }

        $booking = Booking::create([
            'user_id'       => auth()->id(),
            'customer_name' => auth()->user()->name,
            'court_id'      => $court->id,
            'date'          => $validated['date'],
            'start_time'    => $start->format('H:i:s'),
            'end_time'      => $end->format('H:i:s'),
            'amount'        => $court->hourly_rate * $validated['duration'],
            'status'        => 'pending',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Booking submitted! Awaiting confirmation.',
                'booking' => $booking,
            ]);
        }

        return redirect()
            ->route('user.dashboard')
            ->with('success', 'Booking submitted! Awaiting confirmation.');
    }

    private function bookingFailed(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['start_time' => $message]);
    }
}