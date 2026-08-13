<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Equipment;
use App\Models\Membership;
use App\Models\UnmatchedPayment;
use App\Support\ActivityLogger;
use App\Support\PaymentReference;
use App\Support\PaymentWindows;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class User_UserController extends Controller
{
    // Kept in sync with GuestBookingController's constants on purpose —
    // same court, same operating hours. STEP_MINUTES/MIN/MAX are slot
    // *counts* now (matching the guest multi-slot picker), not hours:
    // MIN_DURATION_SLOTS=2 * 30min = 1hr min, MAX_DURATION_SLOTS=6 * 30min
    // = 3hr max, same real-world bounds as the old start+duration model.
    private const OPEN_HOUR = 16;
    private const CLOSE_HOUR = 7;
    private const BOOKING_STEP_MINUTES = 30;
    private const MIN_DURATION_SLOTS = 2;
    private const MAX_DURATION_SLOTS = 6;

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
            // Prefills the contact-number step in book.js when set — see
            // User::phone_number, editable from the profile page. Empty
            // string (not null) so the blade attribute never renders the
            // literal word "null".
            'userPhone'   => auth()->user()->phone_number ?? '',
            'openHour'    => self::OPEN_HOUR,
            'closeHour'   => self::CLOSE_HOUR,
            'minDuration' => self::MIN_DURATION_SLOTS,
            'maxDuration' => self::MAX_DURATION_SLOTS,
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
     * explicitly rather than relying on the route alone. Also doubles as
     * the "give up on a pending GCash/Landbank payment" action the
     * checkout's waiting modal calls — it's a no-op unless the booking
     * is still pending or otherwise cancellable, so it's safe to reuse.
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
     * Lets the checkout page's "waiting for payment" modal poll for the
     * outcome of a pending GCash/Landbank booking. Unlike the guest
     * version (which has no session and relies on a poll_token), the
     * user is authenticated, so ownership alone gates this.
     */
    public function bookingStatus(Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        return response()->json([
            'status' => $booking->status,
        ]);
    }

    /**
     * Return existing (non-cancelled) bookings for a court on a given
     * date, so the front end can grey out taken hours. Returns each
     * booking's actual reserved hours (its booking_slots rows) rather
     * than its full start-to-end envelope, matching
     * GuestBookingController::availability() — a booking spanning
     * 4-5 PM and 9-10 PM shouldn't greay out the empty gap in between.
     * Older duration-only bookings (no slot rows) fall back to their
     * envelope.
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
            ->with('slots')
            ->get(['id', 'start_time', 'end_time']);

        $booked = [];
        foreach ($bookings as $booking) {
            if ($booking->slots->isNotEmpty()) {
                foreach ($booking->slots as $slot) {
                    $booked[] = [
                        'start' => substr($slot->start_time, 0, 5),
                        'end'   => substr($slot->end_time, 0, 5),
                    ];
                }
            } else {
                $booked[] = [
                    'start' => substr($booking->start_time, 0, 5),
                    'end'   => substr($booking->end_time, 0, 5),
                ];
            }
        }

        return response()->json(['booked' => $booked]);
    }

    /**
     * Active equipment plus how many of each are actually free across
     * every selected hour, mirroring GuestBookingController's version.
     */
    public function equipmentAvailability(Request $request)
    {
        $validated = $request->validate([
            'date'    => ['required', 'date'],
            'slots'   => ['required', 'array', 'min:1'],
            'slots.*' => ['required', 'date_format:H:i', 'distinct'],
        ]);

        $windows = collect($validated['slots'])->map(function ($time) {
            $start = Carbon::parse($time);
            $end   = $start->copy()->addMinutes(self::BOOKING_STEP_MINUTES);
            return [$start->format('H:i:s'), $end->format('H:i:s')];
        });

        $equipment = Equipment::where('status', 'active')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'equipment' => $equipment->map(function ($item) use ($validated, $windows) {
                $available = $windows
                    ->map(fn ($w) => $item->availableStock($validated['date'], $w[0], $w[1]))
                    ->min();

                return [
                    'id'        => $item->id,
                    'name'      => $item->name,
                    'category'  => $item->category,
                    'price'     => $item->price,
                    'available' => $available,
                ];
            }),
        ]);
    }

    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'court_id'       => ['required', 'integer', 'exists:courts,id'],
            'date'           => ['required', 'date', 'after_or_equal:today'],
            // Individually selected hours, not required to be contiguous
            // — same model as the guest picker.
            'slots'          => [
                'required',
                'array',
                'min:' . self::MIN_DURATION_SLOTS,
                'max:' . self::MAX_DURATION_SLOTS,
            ],
            'slots.*'        => ['required', 'date_format:H:i', 'distinct'],
            // GCash and Landbank are the only payment methods now — the
            // QR code is static, so nothing here proves payment on its
            // own. GcashWebhookController / LandbankWebhookController
            // are what actually confirm it, by matching the amount (and
            // reference number, when needed) against the SMS receipt.
            'payment_method' => ['required', 'in:gcash,landbank'],
            // Loose on purpose (7-30 digits/spaces/dashes/parens/+) to
            // accept PH mobile numbers in several common formats.
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'equipment'            => ['array'],
            'equipment.*.id'       => ['required_with:equipment', 'integer', 'exists:equipment,id'],
            'equipment.*.quantity' => ['required_with:equipment', 'integer', 'min:1', 'max:20'],
            // User-entered, never trusted on its own — only used to
            // disambiguate when two pending bookings share the exact
            // same amount. The matching webhook controller is the only
            // thing that actually confirms payment, from the real SMS.
            'payment_reference' => ['required', 'string', 'max:50'],
        ]);

        $court = Court::findOrFail($validated['court_id']);
        $user  = auth()->user();

        // Same overnight-wrap handling as the guest flow (OPEN_HOUR can
        // be later in the clock than CLOSE_HOUR, e.g. 4 PM to 7 AM).
        $overnight = self::CLOSE_HOUR <= self::OPEN_HOUR;
        $open  = Carbon::parse($validated['date'])->setTime(self::OPEN_HOUR, 0);
        $close = Carbon::parse($validated['date'])->setTime(self::CLOSE_HOUR, 0);
        if ($overnight) {
            $close->addDay();
        }

        $slotWindows = [];
        foreach ($validated['slots'] as $time) {
            $start = Carbon::parse($validated['date'] . ' ' . $time);

            if ($overnight && $start->lt($open)) {
                $start->addDay();
            }

            $end = $start->copy()->addMinutes(self::BOOKING_STEP_MINUTES);

            if (! ($start->gte($open) && $end->lte($close))) {
                return response()->json([
                    'message' => "The {$time} slot falls outside operating hours.",
                ], 422);
            }

            $slotWindows[] = [$start, $end];
        }

        usort($slotWindows, fn ($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);
        $envelopeStart = $slotWindows[0][0];
        $envelopeEnd   = $slotWindows[count($slotWindows) - 1][1];

        // Apply the signed-in user's active membership discount, if any,
        // to the court portion of the price (not equipment).
        $activeMembership = Membership::with('plan')
            ->where('user_id', auth()->id())
            ->where('status', 'active')
            ->where('expiry_date', '>=', now())
            ->orderByDesc('expiry_date')
            ->first();
        $discountPercent = $activeMembership?->plan?->discount_percent ?? 0;

        $slotPrice = round($court->hourly_rate * (self::BOOKING_STEP_MINUTES / 60) * (1 - $discountPercent / 100), 2);

        do {
            $pollToken = Str::random(40);
        } while (Booking::where('poll_token', $pollToken)->exists());

        $expiresAt = now()->addMinutes(PaymentWindows::BOOKING_EXPIRY_MINUTES);

        try {
            $booking = DB::transaction(function () use ($validated, $court, $user, $envelopeStart, $envelopeEnd, $slotWindows, $slotPrice, $discountPercent, $pollToken, $expiresAt) {
                // Lock the court row for the rest of this transaction —
                // see GuestBookingController::store() for why (closes the
                // double-booking race between the conflict check and the
                // insert below).
                Court::where('id', $court->id)->lockForUpdate()->first();

                foreach ($slotWindows as [$slotStart, $slotEnd]) {
                    $slotConflict = \App\Models\BookingSlot::whereHas('booking', function ($q) use ($court, $validated) {
                            $q->where('court_id', $court->id)
                                ->where('date', $validated['date'])
                                ->where('status', '!=', 'cancelled');
                        })
                        ->where('start_time', '<', $slotEnd->format('H:i:s'))
                        ->where('end_time', '>', $slotStart->format('H:i:s'))
                        ->exists();

                    $legacyConflict = Booking::where('court_id', $court->id)
                        ->where('date', $validated['date'])
                        ->where('status', '!=', 'cancelled')
                        ->whereDoesntHave('slots')
                        ->where('start_time', '<', $slotEnd->format('H:i:s'))
                        ->where('end_time', '>', $slotStart->format('H:i:s'))
                        ->exists();

                    if ($slotConflict || $legacyConflict) {
                        throw new \RuntimeException('That slot was just taken. Please pick another time.');
                    }
                }

                $equipmentLines = collect($validated['equipment'] ?? []);
                $resolvedEquipment = [];

                foreach ($equipmentLines as $line) {
                    $item = Equipment::where('id', $line['id'])->lockForUpdate()->first();

                    if ($item === null) {
                        throw new \RuntimeException('One of the selected rental items no longer exists.');
                    }

                    $minAvailable = collect($slotWindows)
                        ->map(fn ($w) => $item->availableStock($validated['date'], $w[0]->format('H:i:s'), $w[1]->format('H:i:s')))
                        ->min();

                    if ($line['quantity'] > $minAvailable) {
                        throw new \RuntimeException("Only {$minAvailable} \"{$item->name}\" left for one of your selected hours — please adjust your rental.");
                    }

                    $resolvedEquipment[] = ['item' => $item, 'quantity' => $line['quantity']];
                }

                $courtAmount = $slotPrice * count($slotWindows);
                $equipmentAmount = collect($resolvedEquipment)
                    ->sum(fn ($line) => $line['item']->price * $line['quantity']);
                $amount = round($courtAmount + $equipmentAmount, 2);

                // A user can pay via the QR code before finishing this
                // form — if that SMS already arrived, the webhook
                // controller couldn't find a pending booking to attach it
                // to yet and parked it as an UnmatchedPayment. Claim it
                // now the same way the webhook itself would've matched
                // it: the user-typed reference number must match the
                // parked payment's real reference number.
                $unmatchedCandidates = UnmatchedPayment::unmatched()
                    ->where('payment_method', $validated['payment_method'])
                    ->where('amount', $amount)
                    ->where('created_at', '>=', now()->subMinutes(PaymentWindows::claimWindowMinutes($validated['payment_method'])))
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get();

                $claimedPayment = null;
                if ($unmatchedCandidates->isNotEmpty()) {
                    $normalizedRef = PaymentReference::normalize($validated['payment_reference']);

                    $claimedPayment = $normalizedRef !== ''
                        ? $unmatchedCandidates->first(function ($p) use ($normalizedRef) {
                            return PaymentReference::normalize((string) $p->reference_number) === $normalizedRef;
                        })
                        : null;
                }

                $booking = Booking::create([
                    'user_id'        => auth()->id(),
                    'customer_name'  => $user->name,
                    'contact_number' => $validated['contact_number'],
                    'email'          => $user->email,
                    'court_id'       => $court->id,
                    'date'           => $validated['date'],
                    'start_time'     => $envelopeStart->format('H:i:s'),
                    'end_time'       => $envelopeEnd->format('H:i:s'),
                    'amount'         => $amount,
                    'payment_method' => $validated['payment_method'],
                    'gcash_reference_number' => $validated['payment_reference'],
                    'poll_token'     => $pollToken,
                    'expires_at'     => $expiresAt,
                    'status'         => $claimedPayment ? 'paid' : 'pending',
                    'confirmed_at'   => $claimedPayment ? now() : null,
                ]);

                if ($claimedPayment) {
                    $claimedPayment->update([
                        'matched_booking_id' => $booking->id,
                        'matched_at'         => now(),
                    ]);
                }

                foreach ($slotWindows as [$start, $end]) {
                    $booking->slots()->create([
                        'start_time' => $start->format('H:i:s'),
                        'end_time'   => $end->format('H:i:s'),
                        'price'      => $slotPrice,
                    ]);
                }

                foreach ($resolvedEquipment as $line) {
                    $booking->equipment()->create([
                        'equipment_id' => $line['item']->id,
                        'quantity'     => $line['quantity'],
                        'price_each'   => $line['item']->price,
                    ]);
                }

                return $booking;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLogger::log(
            'booking.created',
            $user->name . " booked {$court->name} on " . $envelopeStart->format('M j, Y') . ' from ' . $envelopeStart->format('g:i A') . ' to ' . $envelopeEnd->format('g:i A') . '.',
            subject: $booking,
            properties: [
                'court_id'         => $court->id,
                'date'             => $validated['date'],
                'start_time'       => $booking->start_time,
                'end_time'         => $booking->end_time,
                'amount'           => $booking->amount,
                'payment_method'   => $validated['payment_method'],
                'discount_percent' => $discountPercent,
            ],
        );

        return response()->json([
            'message'    => $booking->status === 'paid'
                ? 'Booking confirmed! We matched it to a payment that already came in.'
                : 'Booking submitted! Waiting for payment confirmation.',
            'booking'    => $booking,
            'booking_id' => $booking->id,
            'expires_at' => $booking->expires_at?->toIso8601String(),
            'amount'     => $booking->amount,
        ]);
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
            // Optional — same loose PH-mobile-friendly pattern as the
            // booking flow's contact_number field. Nullable so someone
            // can clear it back out if they want to.
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
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

        Booking::where('user_id', $user->user_id)
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', today())
            ->update(['status' => 'cancelled']);

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