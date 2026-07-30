<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Membership;
use App\Support\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        ActivityLogger::log(
            'booking.cancelled',
            auth()->user()->name . " cancelled a booking for Court {$booking->court_id} on " . Carbon::parse($booking->date)->format('M j, Y') . ".",
            subject: $booking,
            properties: [
                'court_id'   => $booking->court_id,
                'date'       => (string) $booking->date,
                'start_time' => $booking->start_time,
            ],
        );

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

        // Apply the signed-in user's active membership discount, if any.
        $baseAmount = $court->hourly_rate * $validated['duration'];

        $activeMembership = Membership::with('plan')
            ->where('user_id', auth()->id())
            ->where('status', 'active')
            ->where('expiry_date', '>=', now())
            ->orderByDesc('expiry_date')
            ->first();

        $discountPercent = $activeMembership?->plan?->discount_percent ?? 0;
        $amount = round($baseAmount * (1 - $discountPercent / 100), 2);

        $booking = Booking::create([
            'user_id'       => auth()->id(),
            'customer_name' => auth()->user()->name,
            'court_id'      => $court->id,
            'date'          => $validated['date'],
            'start_time'    => $start->format('H:i:s'),
            'end_time'      => $end->format('H:i:s'),
            'amount'        => $amount,
            'payment_method' => $validated['payment_method'],
            'status'        => 'pending',
        ]);

        ActivityLogger::log(
            'booking.created',
            auth()->user()->name . " booked {$court->name} on " . $start->format('M j, Y') . ' from ' . $start->format('g:i A') . ' to ' . $end->format('g:i A') . '.',
            subject: $booking,
            properties: [
                'court_id'         => $court->id,
                'date'             => $validated['date'],
                'start_time'       => $booking->start_time,
                'end_time'         => $booking->end_time,
                'duration_hours'   => $validated['duration'],
                'amount'           => $amount,
                'payment_method'   => $validated['payment_method'],
                'discount_percent' => $discountPercent,
            ],
        );

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
            'user'     => auth()->user(),
            'userName' => auth()->user()->name,
        ]);
    }

    /**
     * Update the signed-in user's own profile fields. Deliberately does
     * NOT accept 'role' here — that must stay admin-only, regardless of
     * what a request tries to send, so it's simply never read from input.
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'username')->ignore($user->user_id, 'user_id'),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
        ]);

        $originalName = $user->name;

        $user->update($validated);

        ActivityLogger::log(
            'profile.updated',
            "{$originalName} updated their profile.",
            subject: $user,
            properties: ['changed_fields' => array_keys($user->getChanges())],
        );

        return redirect()
            ->route('user.profile')
            ->with('success', 'Profile updated.');
    }

    /**
     * Permanently delete the signed-in user's own account. The typed
     * confirmation phrase is checked here too, not just in the browser —
     * client-side gating is just UX, this is the actual security check.
     */
    public function destroyAccount(Request $request)
    {
        $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        if ($request->input('confirmation') !== 'DELETE MY ACCOUNT') {
            return back()->withErrors([
                'confirmation' => 'Type "DELETE MY ACCOUNT" exactly to confirm.',
            ]);
        }

        $user = auth()->user();

        // Free up this user's upcoming slots so other players can book them.
        // Past bookings are left as-is — they're historical record, not
        // something that needs "freeing up".
        Booking::where('user_id', $user->user_id)
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', today())
            ->update(['status' => 'cancelled']);

        // Logged before the actual delete/logout below, while $user (and
        // auth()) still resolves to a real account — the FK is
        // nullOnDelete, so this row survives the account's removal with
        // just its user_name snapshot intact.
        ActivityLogger::log(
            'account.deleted',
            "{$user->name} deleted their own account.",
            actor: $user,
            subject: $user,
        );

        auth()->logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}