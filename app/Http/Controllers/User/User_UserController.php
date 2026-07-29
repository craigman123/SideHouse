<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use Carbon\Carbon;
use Illuminate\Http\Request;

class User_UserController extends Controller
{
    private const OPEN_HOUR = 16;  
    private const CLOSE_HOUR = 7;
    private const BOOKING_STEP_MINUTES = 15;
    private const MIN_DURATION_HOURS = 1;
    private const MAX_DURATION_HOURS = 3;

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
            'courts'      => $courts,
            'userName'    => auth()->user()->name,
            'openHour'    => self::OPEN_HOUR,
            'closeHour'   => self::CLOSE_HOUR,
            'minDuration' => self::MIN_DURATION_HOURS,
            'maxDuration' => self::MAX_DURATION_HOURS,
            'stepMinutes' => self::BOOKING_STEP_MINUTES,
        ]);
    }

    /**
     * Full booking history for the signed-in user: paginated, filterable
     * by status (all/pending/paid/cancelled) via ?status=.
     */
    public function myBookings(Request $request)
    {
        $status = $request->query('status', 'all');

        $bookings = Booking::where('user_id', auth()->id())
            ->status($status)
            ->orderByDesc('date')
            ->orderByDesc('start_time')
            ->paginate(10)
            ->withQueryString();

        return view('user.bookings.index', [
            'bookings' => $bookings,
            'status'   => $status,
            'userName' => auth()->user()->name,
        ]);
    }

    /**
     * Cancel a booking. Route-model-bound, but we still verify ownership
     * explicitly rather than relying on the route alone.
     */
    public function cancelBooking(Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        if ($booking->status === 'cancelled') {
            return response()->json([
                'message' => 'This booking is already cancelled.',
            ], 422);
        }

        $booking->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Booking cancelled.',
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
            'court_id'       => ['required', 'integer', 'exists:courts,id'],
            'date'           => ['required', 'date', 'after_or_equal:today'],
            'start_time'     => ['required', 'date_format:H:i'],
            'payment_method' => ['required', 'in:arrival,ewallet'],
            'duration'       => [
                'required',
                'numeric',
                'min:' . self::MIN_DURATION_HOURS,
                'max:' . self::MAX_DURATION_HOURS,
                function ($attribute, $value, $fail) {
                    $steps = ($value * 60) / self::BOOKING_STEP_MINUTES;
                    if (abs($steps - round($steps)) > 0.001) {
                        $fail('Duration must be in ' . self::BOOKING_STEP_MINUTES . '-minute increments.');
                    }
                },
            ],
        ]);

        $court = Court::findOrFail($validated['court_id']);

        $start = Carbon::parse($validated['date'] . ' ' . $validated['start_time']);
        $end   = $start->copy()->addMinutes((int) round($validated['duration'] * 60));

        // Operating hours may wrap past midnight (e.g. OPEN_HOUR=16, CLOSE_HOUR=7
        // means the court is open 4:00 PM to 7:00 AM the next day). Build the
        // actual opening/closing instants around the booking's start date,
        // shifting them a day as needed so both wrap directions are handled.
        $overnight = self::CLOSE_HOUR <= self::OPEN_HOUR;

        $open  = $start->copy()->setTime(self::OPEN_HOUR, 0);
        $close = $start->copy()->setTime(self::CLOSE_HOUR, 0);

        if ($overnight) {
            $close->addDay();

            // A start time before OPEN_HOUR (e.g. 2 AM) belongs to the
            // previous day's window (opened at 4 PM yesterday, closes 7 AM
            // today), so slide the window back a day to match.
            if ($start->lt($open)) {
                $open->subDay();
                $close->subDay();
            }
        }

        $withinHours = $start->gte($open) && $end->lte($close);

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
            'payment_method' => $validated['payment_method'],
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

    public function profile()
    {
        return view('user.profile.profile', [
            'user' => auth()->user(),
            'userName' => auth()->user()->name,
        ]);
    }
}