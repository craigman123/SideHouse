<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\CreditWallet;
use App\Models\Equipment;
use App\Models\Membership;
use App\Models\PaymentReference as PaymentReferenceModel;
use App\Services\CreditService;
use App\Services\BookingAvailabilityService;
use App\Services\LumiSuggestionService;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use App\Support\GuardSessionState;
use App\Support\PaymentWindows;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class User_UserController extends Controller
{
    // Operating hours, step length, and min/max duration now come from
    // BookingHours (backed by the business_settings table) everywhere in
    // this controller, matching GuestBookingController — this used to
    // keep its own hardcoded copy of these values, which meant an admin
    // changing the schedule silently desynced the two booking flows.

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

    public function createBooking(Request $request)
    {
        $courts = Court::where('status', 'active')
            ->orderBy('name')
            ->get();

        // book_blade.php / book.js already read data-closed-weekdays and
        // data-closure-dates to grey out closed dates on the calendar —
        // this controller just wasn't supplying them, so the calendar
        // silently fell back to "nothing is ever closed" via the blade's
        // ?? [] / ?? collect() defaults. Mirrors
        // GuestBookingController::landing()'s scoping: closures that are
        // either store-wide (court_id null) or specific to whichever
        // court ends up selected. The user picker lets them switch
        // between courts (unlike the guest widget's single-court flow),
        // so closures for every active court are included rather than
        // just the first one.
        $courtIds = $courts->pluck('id');

        $closureDates = CourtClosure::upcoming()
            ->where(function ($query) use ($courtIds) {
                $query->whereNull('court_id');
                if ($courtIds->isNotEmpty()) {
                    $query->orWhereIn('court_id', $courtIds);
                }
            })
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->unique()
            ->values();

        // See GuestBookingController::landing() for why this is
        // display-only: storeBooking() below is the actual source of
        // truth for what a slot costs.
        $settings = BusinessSetting::current();

        // Load the user's credit wallet so the booking page can show
        // the "Use my credits" section without an extra round-trip.
        $creditWallet = CreditWallet::where('user_id', auth()->id())->first();
        $creditBalance = $creditWallet ? [
            'given'    => (int) $creditWallet->given_balance,
            'reserved' => (int) $creditWallet->reserved_balance,
            'total'    => (int) $creditWallet->given_balance + (int) $creditWallet->reserved_balance,
        ] : null;

        $deepLink = $this->bookingDeepLink($request, $courts);
        if ($request->query('source') === 'lumi') {
            $request->session()->put(GuardSessionState::key('lumi_suggestions_seen'), true);
        }

        return view('user.bookings.book', [
            'userName'            => auth()->user()->name,
            'userPhone'           => auth()->user()->phone_number ?? '',
            'courts'              => $courts,
            'openHour'            => BookingHours::openHour(),
            'closeHour'           => BookingHours::closeHour(),
            'minDuration'         => BookingHours::minDurationHours(),
            'maxDuration'         => BookingHours::maxDurationHours(),
            'stepMinutes'         => BookingHours::stepMinutes(),
            'closedWeekdays'      => BookingHours::closedWeekdays(),
            'closureDates'        => $closureDates,
            'peakStartHour'       => $settings->hasPeakPricing() ? $settings->peak_start_hour : null,
            'peakEndHour'         => $settings->hasPeakPricing() ? $settings->peak_end_hour : null,
            'peakAdjustmentType'  => $settings->hasPeakPricing() ? $settings->peak_adjustment_type : null,
            'peakAdjustmentValue' => $settings->hasPeakPricing() ? $settings->peak_adjustment_value : null,
            'creditBalance'       => $creditBalance,
            'deepLink'            => $deepLink,
        ]);
    }

    private function bookingDeepLink(Request $request, $courts): array
    {
        $timezone = (string) config('lumi.timezone', 'Asia/Manila');
        $rawDate = $request->query('date');
        $date = null;
        if (is_string($rawDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate, new \DateTimeZone($timezone));
            $parseErrors = \DateTimeImmutable::getLastErrors();
            if ($parsedDate
                && ($parseErrors === false || ($parseErrors['warning_count'] === 0 && $parseErrors['error_count'] === 0))
                && $parsedDate->format('Y-m-d') === $rawDate
                && $rawDate >= Carbon::now($timezone)->toDateString()) {
                $date = $rawDate;
            }
        }

        $rawTime = $request->query('time');
        $time = is_string($rawTime)
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $rawTime)
            && ((int) substr($rawTime, 3, 2) % max(1, BookingHours::stepMinutes())) === 0
                ? $rawTime
                : null;
        if ($date === Carbon::now($timezone)->toDateString() && $time !== null) {
            $requestedStart = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, $timezone);
            if ($requestedStart->lessThanOrEqualTo(Carbon::now($timezone)->addHour())) {
                $time = null;
            }
        }

        $courtId = null;
        if ($request->query->has('court')) {
            $rawCourt = $request->query('court');
            if (! is_string($rawCourt) || ! ctype_digit($rawCourt)) {
                return ['date' => null, 'time' => null, 'court_id' => null];
            }
            $courtId = $courts->first(fn (Court $court) => (int) $court->id === (int) $rawCourt)?->id;
            if ($courtId === null) {
                return ['date' => null, 'time' => null, 'court_id' => null];
            }
        } elseif ($date !== null && $time !== null) {
            $courtId = $courts->first()?->id;
        }

        if ($date === null) {
            $time = null;
        }

        return [
            'date' => $date,
            'time' => $time,
            'court_id' => $courtId === null ? null : (int) $courtId,
        ];
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

    public function cancelBooking(Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        $user = auth()->user();

        $result = DB::transaction(function () use ($booking, $user) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            // Idempotency: already cancelled → 422, never refund twice.
            if ($locked->status === 'cancelled') {
                return ['ok' => false, 'status' => 'cancelled'];
            }

            // Past bookings cannot be self-cancelled.
            if ($locked->date < today()) {
                return ['ok' => false, 'status' => 'past'];
            }

            // Only pending and paid bookings are cancellable. Any other
            // status (expired, etc.) is rejected.
            if (! in_array($locked->status, ['pending', 'paid'], true)) {
                return ['ok' => false, 'status' => $locked->status];
            }

            $refundAmount = 0;

            if ($locked->status === 'pending') {
                // Pending: release the credit hold (given → given, reserved →
                // reserved) if one exists, as before. No new refund is issued
                // because the user was never charged cash.
                if ($locked->payment_reference_id) {
                    $lockedRef = \App\Models\PaymentReference::where('id', $locked->payment_reference_id)
                        ->lockForUpdate()
                        ->first();
                    if ($lockedRef) {
                        app(CreditService::class)->releasePaymentHold($lockedRef);
                    }
                }
            } else {
                // Paid: refund everything the user actually paid for this
                // specific booking row as reserved credits.
                // Formula: given_used + reserved_used + cash_paid
                // (cash_paid is set by PaymentReferenceObserver when the QR
                // webhook confirms; for a fully-credit booking it is 0).
                $refundAmount = (int) $locked->given_used
                              + (int) $locked->reserved_used
                              + (int) $locked->cash_paid;

                if ($refundAmount > 0) {
                    app(CreditService::class)->refundForCancellation(
                        $user,
                        $refundAmount,
                        $locked->id
                    );
                }

                // If every booking under this PaymentReference is now
                // cancelled, mark the reference cancelled too.
                if ($locked->payment_reference_id) {
                    $lockedRef = \App\Models\PaymentReference::where('id', $locked->payment_reference_id)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedRef) {
                        $siblingStatuses = Booking::where('payment_reference_id', $lockedRef->id)
                            ->where('id', '!=', $locked->id)
                            ->pluck('status');

                        $allCancelled = $siblingStatuses->every(fn ($s) => $s === 'cancelled');
                        if ($allCancelled) {
                            $lockedRef->update(['status' => 'cancelled']);
                        }
                    }
                }
            }

            $locked->update(['status' => 'cancelled']);
            // Free the booked slots so the court is available again.
            $locked->slots()->update(['is_active' => false]);

            return ['ok' => true, 'booking' => $locked, 'refundAmount' => $refundAmount];
        });

        if (! $result['ok']) {
            $message = match ($result['status']) {
                'cancelled' => 'This booking is already cancelled.',
                'past'      => 'Past bookings cannot be cancelled.',
                default     => 'This booking can no longer be cancelled.',
            };

            return response()->json(['message' => $message], 422);
        }

        $booking      = $result['booking'];
        $refundAmount = $result['refundAmount'];
        app(LumiSuggestionService::class)->forgetForUser((int) $user->user_id);
        session()->forget(GuardSessionState::key('lumi_suggestions_seen'));

        ActivityLogger::log(
            'booking.cancelled',
            $user->name . " cancelled a booking for Court {$booking->court_id} on " . Carbon::parse($booking->date)->format('M j, Y') . ".",
            subject: $booking,
            properties: [
                'court_id'     => $booking->court_id,
                'date'         => (string) $booking->date,
                'start_time'   => $booking->start_time,
                'refund_amount' => $refundAmount,
            ],
        );

        $message = $refundAmount > 0
            ? 'Booking cancelled. ₱' . number_format($refundAmount) . ' added to your reserved credits.'
            : 'Booking cancelled.';

        return response()->json(['message' => $message]);
    }

    /**
     * Lets the checkout page's "waiting for payment" step poll for the
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
     * Full-page replacement for the old "waiting for payment" modal —
     * see GuestBookingController::waiting()'s docblock for the reasoning
     * (accidental cancel-on-close, lost state on refresh). Ownership-
     * gated the same way bookingStatus()/cancelBooking() are, since the
     * user is authenticated and needs no poll_token.
     */
    public function waitingForPayment(Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        $booking->load('court');

        // Same reasoning as GuestBookingController::waiting() — a
        // multi-date checkout shares one payment_reference across
        // several Booking rows, so pull every sibling under the same
        // payment rather than showing just the one date this booking
        // row happens to be.
        $siblingBookings = Booking::where('payment_reference_id', $booking->payment_reference_id)
            ->orderBy('date')
            ->with('court')
            ->get();

        $paymentReference = \App\Models\PaymentReference::find($booking->payment_reference_id);

        return view('user.bookings.payment-waiting', [
            'booking'            => $booking,
            'userName'           => auth()->user()->name,
            'siblingBookings'    => $siblingBookings,
            'totalAmount'        => $paymentReference->amount ?? $siblingBookings->sum('amount'),
            'statusUrl'          => route('book.status', ['booking' => $booking->id]),
            'cancelUrl'          => route('user.bookings.cancel', ['booking' => $booking->id]),
            'bookUrl'            => route('book.index'),
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

        return response()->json(app(BookingAvailabilityService::class)
            ->forCourtDate((int) $validated['court_id'], $validated['date']));
    }

    /**
     * Active equipment plus how many of each are actually free across
     * every selected hour, mirroring GuestBookingController's version.
     * `slots.*` is "Y-m-d H:i" now (real date + time) — see
     * storeBooking()'s docblock for why.
     */
    public function equipmentAvailability(Request $request)
    {
        $validated = $request->validate([
            'slots'   => ['required', 'array', 'min:1'],
            'slots.*' => ['required', 'date_format:Y-m-d H:i', 'distinct'],
            'court_id' => ['required', 'integer', 'exists:courts,id'],
        ]);

        $stepMinutes = BookingHours::stepMinutes();

        $windows = collect($validated['slots'])->map(function ($key) use ($stepMinutes) {
            $start = Carbon::parse($key);
            $end   = $start->copy()->addMinutes($stepMinutes);
            return [$start->toDateString(), $start->format('H:i:s'), $end->format('H:i:s')];
        });

        if (! empty($validated['court_id'])) {
            foreach ($windows as $window) {
                $start = Carbon::parse($window[0].' '.$window[1], 'Asia/Manila');
                $end = Carbon::parse($window[0].' '.$window[2], 'Asia/Manila');
                if ($end->lessThanOrEqualTo($start)) $end->addDay();
                if (app(\App\Services\CourtBlockClosureAvailability::class)->firstClosedOverlap((int) $validated['court_id'], $start, $end)) {
                    return response()->json(['message' => 'This court is closed during one of the selected times.'], 422);
                }
            }
        }

        $equipment = Equipment::where('status', 'active')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'equipment' => $equipment->map(function ($item) use ($windows) {
                $available = $windows
                    ->map(fn ($w) => $item->availableStock($w[0], $w[1], $w[2]))
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

    /**
     * Creates one Booking per distinct calendar date the user selected,
     * all sharing a single PaymentReference. Credits are applied when the
     * user checks "Use my credits" — the server recomputes the split from
     * the wallet (never trusting browser-sent amounts), holds/debits the
     * wallet inside the same DB transaction as the booking rows, and
     * returns status='paid' when credits cover the full total so the JS
     * can redirect straight to the receipt page.
     *
     * See GuestBookingController::store()'s docblock — this is the same
     * fix, applied to the signed-in flow.
     */
    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'court_id'          => ['required', 'integer', 'exists:courts,id'],
            'date'              => ['required', 'date', 'after_or_equal:today'],
            'slots'             => ['required', 'array', 'min:1', 'max:60'],
            'slots.*'           => ['required', 'date_format:Y-m-d H:i', 'distinct'],
            'payment_method'    => ['nullable', 'in:qrph'],
            'contact_number'    => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            // use_credit: 1/0 from the browser. The server recomputes the
            // actual split — this is only the user's intent toggle.
            'use_credit'        => ['nullable', 'boolean'],
            // idempotency_token: a random key the browser generates once
            // per checkout session. Used to deduplicate double-submits
            // the same way the guest flow does.
            'idempotency_token' => ['required', 'string', 'min:32', 'max:128'],
            'equipment'            => ['array'],
            'equipment.*.id'       => ['required_with:equipment', 'integer', 'exists:equipment,id'],
            'equipment.*.quantity' => ['required_with:equipment', 'integer', 'min:1', 'max:20'],
        ]);

        $court = Court::findOrFail($validated['court_id']);
        $user  = auth()->user();

        $openHour    = BookingHours::openHour();
        $closeHour   = BookingHours::closeHour();
        $stepMinutes = BookingHours::stepMinutes();
        $overnight   = $closeHour <= $openHour;

        $slotWindows = [];
        foreach ($validated['slots'] as $key) {
            $start = Carbon::createFromFormat('Y-m-d H:i', $key);
            $end   = $start->copy()->addMinutes($stepMinutes);

            // The top-level `date` field is only validated as
            // today-or-later, and each slot carries its own real
            // calendar date — see GuestBookingController::resolveSlotGroups()
            // for why each one needs its own past-date check too.
            if ($start->lt(now())) {
                return response()->json([
                    'message' => "The {$start->format('M j, Y g:i A')} slot has already passed.",
                ], 422);
            }

            $sameDayOpen  = $start->copy()->startOfDay()->setTime($openHour, 0);
            $sameDayClose = $overnight
                ? $sameDayOpen->copy()->addDay()->setTime($closeHour, 0)
                : $start->copy()->startOfDay()->setTime($closeHour, 0);
            $withinSameDaySession = $start->gte($sameDayOpen) && $end->lte($sameDayClose);

            $withinPrevDaySession = false;
            if ($overnight) {
                $prevDayOpen  = $sameDayOpen->copy()->subDay();
                $prevDayClose = $prevDayOpen->copy()->addDay()->setTime($closeHour, 0);
                $withinPrevDaySession = $start->gte($prevDayOpen) && $end->lte($prevDayClose);
            }

            if (! $withinSameDaySession && ! $withinPrevDaySession) {
                return response()->json([
                    'message' => "The {$start->format('M j, Y g:i A')} slot falls outside operating hours.",
                ], 422);
            }

            $slotWindows[] = [$start, $end];
        }

        usort($slotWindows, fn ($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);

        $groups = [];
        foreach ($slotWindows as $window) {
            $groups[$window[0]->toDateString()][] = $window;
        }
        ksort($groups);

        // Same formula as GuestBookingController::resolveSlotGroups() —
        // derived from the admin-configured min/max duration rather than
        // a fixed 1–3hr range, so the two flows agree on what's bookable.
        $minSlotsPerDate = max(1, (int) ceil(BookingHours::minDurationHours() * 60 / $stepMinutes));
        $maxSlotsPerDate = max(1, (int) floor(BookingHours::maxDurationHours() * 60 / $stepMinutes));

        foreach ($groups as $dateStr => $windows) {
            if (count($windows) < $minSlotsPerDate || count($windows) > $maxSlotsPerDate) {
                return response()->json([
                    'message' => "Please select between {$minSlotsPerDate} and {$maxSlotsPerDate} slot(s) for "
                        . Carbon::parse($dateStr)->format('M j, Y') . '.',
                ], 422);
            }

            // Same check GuestBookingController does per date — nothing
            // stops a hand-built or replayed request with a since-closed
            // date.
            if (BookingHours::isClosed($validated['court_id'], $dateStr)) {
                return response()->json([
                    'message' => BookingHours::closedReason($validated['court_id'], $dateStr) ?? ('This court is closed on ' . Carbon::parse($dateStr)->format('M j, Y') . '.'),
                ], 422);
            }
            foreach ($windows as [$windowStart, $windowEnd]) {
                if (app(\App\Services\CourtBlockClosureAvailability::class)->firstClosedOverlap((int) $validated['court_id'], $windowStart, $windowEnd)) {
                    return response()->json(['message' => 'This court is closed during one of the selected times. Please choose another time.'], 422);
                }
            }
        }

        // Apply the signed-in user's active membership discount, if any,
        // to the court portion of the price (not equipment).
        $activeMembership = Membership::with('plan')
            ->where('user_id', auth()->id())
            ->where('status', 'active')
            ->where('expiry_date', '>=', today()->toDateString())
            ->orderByDesc('expiry_date')
            ->first();
        $discountPercent = $activeMembership?->plan?->discount_percent ?? 0;

        // Peak surcharge is applied to the base hourly rate per hour slot
        // first (see BusinessSetting::applyPeakAdjustment()'s docblock —
        // a booking spanning the peak boundary has mixed rates across its
        // own hours), then the membership discount is applied on top of
        // that already-adjusted rate.
        $settings = BusinessSetting::current();
        $slotAmountFor = function (Carbon $start) use ($court, $settings, $stepMinutes, $discountPercent) {
            $event = app(\App\Services\OpenPlayAvailability::class)->eventForSlot(
                (int) $court->id, $start->toDateString(), $start->format('H:i:s'), $start->copy()->addMinutes($stepMinutes)->format('H:i:s')
            );
            return $event ? (float) $event->price_per_player : round(
                $settings->applyPeakAdjustment($court->hourly_rate, $start->hour)
                    * ($stepMinutes / 60) * (1 - $discountPercent / 100), 2
            );
        };
        $totalSlotCount = count($slotWindows);

        $expiresAt = now()->addMinutes(PaymentWindows::BOOKING_EXPIRY_MINUTES);

        try {
            $result = DB::transaction(function () use (
                $validated, $court, $user, $groups, $slotAmountFor,
                $discountPercent, $expiresAt
            ) {
                // See GuestBookingController::store() for why this lock
                // is needed (closes the double-booking race between the
                // conflict check and the insert below).
                Court::where('id', $court->id)->lockForUpdate()->first();

                // Idempotency: if this checkout key was already committed
                // (e.g. a double-click or a network retry), return the
                // original bookings without creating duplicates or debiting
                // credits again. Same pattern as the guest flow.
                $checkoutKey = hash('sha256', $validated['idempotency_token']);
                $existingReference = PaymentReferenceModel::query()
                    ->where('credit_checkout_key', $checkoutKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingReference) {
                    $existingBookings = Booking::where('payment_reference_id', $existingReference->id)
                        ->orderBy('date')
                        ->lockForUpdate()
                        ->get();
                    if ($existingBookings->isNotEmpty()) {
                        return [
                            'bookings'         => $existingBookings->all(),
                            'paymentReference' => $existingReference,
                            'isPaid'           => $existingReference->isConfirmed(),
                            'idempotent'       => true,
                        ];
                    }
                }

                foreach ($groups as $dateStr => $windows) {
                    foreach ($windows as [$slotStart, $slotEnd]) {
                        $slotConflict = \App\Models\BookingSlot::whereHas('booking', function ($q) use ($court) {
                                $q->where('court_id', $court->id)
                                    ->where('status', '!=', 'cancelled');
                            })
                            ->where('date', $dateStr)
                            ->where('start_time', '<', $slotEnd->format('H:i:s'))
                            ->where('end_time', '>', $slotStart->format('H:i:s'))
                            ->exists();

                        if (app(\App\Services\CourtBlockClosureAvailability::class)->firstClosedOverlap((int) $court->id, $slotStart, $slotEnd)) {
                            throw new \RuntimeException('This court is closed during one of the selected times. Please choose another time.');
                        }

                        $legacyConflict = Booking::where('court_id', $court->id)
                            ->where('date', $dateStr)
                            ->where('status', '!=', 'cancelled')
                            ->whereDoesntHave('slots')
                            ->where('start_time', '<', $slotEnd->format('H:i:s'))
                            ->where('end_time', '>', $slotStart->format('H:i:s'))
                            ->exists();

                        $openPlay = app(\App\Services\OpenPlayAvailability::class)->eventForSlot(
                            (int) $court->id, $dateStr, $slotStart->format('H:i:s'), $slotEnd->format('H:i:s')
                        );
                        if ($openPlay) {
                            if (app(\App\Services\OpenPlayAvailability::class)->joined($openPlay, (int) $court->id) >= (int) $openPlay->max_players) {
                                throw new \RuntimeException('Open play is full for one of the selected slots. Please choose another time.');
                            }
                        } elseif ($slotConflict || $legacyConflict) {
                            throw new \RuntimeException('That slot was just taken. Please pick another time.');
                        }
                    }
                }

                $equipmentLines = collect($validated['equipment'] ?? []);
                $resolvedEquipment = [];
                $allWindows = collect($groups)->flatten(1);

                foreach ($equipmentLines as $line) {
                    $item = Equipment::where('id', $line['id'])->lockForUpdate()->first();

                    if ($item === null) {
                        throw new \RuntimeException('One of the selected rental items no longer exists.');
                    }

                    $minAvailable = $allWindows
                        ->map(fn ($w) => $item->availableStock($w[0]->toDateString(), $w[0]->format('H:i:s'), $w[1]->format('H:i:s')))
                        ->min();

                    if ($line['quantity'] > $minAvailable) {
                        throw new \RuntimeException("Only {$minAvailable} \"{$item->name}\" left for one of your selected hours — please adjust your rental.");
                    }

                    $resolvedEquipment[] = ['item' => $item, 'quantity' => $line['quantity']];
                }

                $courtAmount = collect($groups)->flatten(1)->sum(fn ($window) => $slotAmountFor($window[0]));
                $equipmentAmount = collect($resolvedEquipment)
                    ->sum(fn ($line) => $line['item']->price * $line['quantity']);
                $totalAmount = round($courtAmount + $equipmentAmount, 2);
                $totalPhp = (int) round($totalAmount);

                // Server-side credit split — the browser only sends
                // use_credit (0 or 1). The wallet is locked here so the
                // balance cannot change between the quote and the debit.
                // quoteAndSpendForGuestCheckout() does its own lockForUpdate()
                // internally; that is a no-op when the same row is already
                // locked in this transaction (PostgreSQL re-entrant lock).
                $split = [
                    'given_used'   => 0,
                    'reserved_used' => 0,
                    'cash_due'     => $totalPhp,
                    'cash_paid'    => 0,
                ];
                if ((bool) ($validated['use_credit'] ?? false)) {
                    $split = app(CreditService::class)->quoteAndSpendForGuestCheckout($user, $totalPhp);
                }
                $isPaid = $split['cash_due'] === 0;

                $paymentReference = PaymentReferenceModel::create([
                    'payment_method'         => 'qrph',
                    'amount'                 => $split['cash_due'],
                    'given_used'             => $split['given_used'],
                    'reserved_used'          => $split['reserved_used'],
                    'cash_due'               => $split['cash_due'],
                    'cash_paid'              => 0,
                    'credit_checkout_key'    => $checkoutKey,
                    'confirmed_at'           => $isPaid ? now() : null,
                    'credit_status'          => $isPaid && ($split['given_used'] + $split['reserved_used'] > 0)
                        ? 'paid'
                        : (($split['given_used'] + $split['reserved_used'] > 0) ? 'held' : 'none'),
                    'matched_credit_user_id' => ($split['given_used'] + $split['reserved_used'] > 0)
                        ? $user->user_id
                        : null,
                ]);

                $bookings = [];
                $isFirstGroup = true;
                // Running totals for distributing the credit split across
                // multiple booking rows — same bucketing as the guest flow.
                $remainingGiven    = (int) $split['given_used'];
                $remainingReserved = (int) $split['reserved_used'];
                $remainingCash     = (int) $split['cash_due'];

                foreach ($groups as $dateStr => $windows) {
                    $envelopeStart = $windows[0][0];
                    $envelopeEnd   = $windows[count($windows) - 1][1];
                    $dateAmount    = round(collect($windows)->sum(fn ($window) => $slotAmountFor($window[0])), 2);
                    $grossBookingAmount = $isFirstGroup
                        ? round($dateAmount + ($totalAmount - $courtAmount), 2)
                        : $dateAmount;
                    $bookingPhp = (int) round($grossBookingAmount);

                    // Allocate credits greedily in given → reserved → cash
                    // order, same as GuestBookingController::store().
                    $allocatedGiven    = min($bookingPhp, $remainingGiven);
                    $remainingGiven   -= $allocatedGiven;
                    $bookingCashLeft   = $bookingPhp - $allocatedGiven;
                    $allocatedReserved = min($bookingCashLeft, $remainingReserved);
                    $remainingReserved -= $allocatedReserved;
                    $bookingCashLeft  -= $allocatedReserved;
                    $allocatedCashDue  = min($bookingCashLeft, $remainingCash);
                    $remainingCash    -= $allocatedCashDue;

                    $booking = Booking::create([
                        'user_id'              => auth()->id(),
                        'customer_name'        => $user->name,
                        'contact_number'       => $validated['contact_number'],
                        'email'                => $user->email,
                        'court_id'             => $court->id,
                        'payment_reference_id' => $paymentReference->id,
                        'payment_method'       => 'qrph',
                        'date'                 => $dateStr,
                        'start_time'           => $envelopeStart->format('H:i:s'),
                        'end_time'             => $envelopeEnd->format('H:i:s'),
                        'given_used'           => $allocatedGiven,
                        'reserved_used'        => $allocatedReserved,
                        'cash_due'             => $allocatedCashDue,
                        'cash_paid'            => $isPaid ? 0 : 0,
                        // See GuestBookingController::store() for why
                        // equipment cost lands entirely on the first date.
                        'amount'               => $grossBookingAmount,
                        'poll_token'           => (function () {
                            do {
                                $token = Str::random(40);
                            } while (Booking::where('poll_token', $token)->exists());
                            return $token;
                        })(),
                        'expires_at'           => $expiresAt,
                        'status'               => $isPaid ? 'paid' : 'pending',
                        'confirmed_at'         => $isPaid ? now() : null,
                    ]);

                    foreach ($windows as [$start, $end]) {
                        $booking->slots()->create([
                            'court_id'   => $court->id,
                            'date'       => $start->toDateString(),
                            'start_time' => $start->format('H:i:s'),
                            'end_time'   => $end->format('H:i:s'),
                            'price'      => $slotAmountFor($start),
                        ]);
                    }

                    if ($isFirstGroup) {
                        foreach ($resolvedEquipment as $line) {
                            $booking->equipment()->create([
                                'equipment_id' => $line['item']->id,
                                'quantity'     => $line['quantity'],
                                'price_each'   => $line['item']->price,
                            ]);
                        }
                    }

                    $bookings[] = $booking;
                    $isFirstGroup = false;
                }

                // Debit or hold credits ONCE for the whole PaymentReference,
                // not once per booking group. debitForCheckout() locks the
                // wallet row itself, so this is safe inside the transaction.
                if ($split['given_used'] + $split['reserved_used'] > 0) {
                    app(CreditService::class)->debitForCheckout(
                        $user,
                        $split['given_used'],
                        $split['reserved_used'],
                        $paymentReference,
                        null  // payment-reference-level, not per-booking
                    );
                }

                return [
                    'bookings'         => $bookings,
                    'paymentReference' => $paymentReference,
                    'isPaid'           => $isPaid,
                ];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        [$bookings, $isPaid] = [$result['bookings'], $result['isPaid']];
        $idempotent = (bool) ($result['idempotent'] ?? false);
        app(LumiSuggestionService::class)->forgetForUser((int) $user->user_id);
        session()->forget(GuardSessionState::key('lumi_suggestions_seen'));

        // Log each booking only on the first (non-idempotent) submit.
        foreach ($bookings as $booking) {
            if ($idempotent) break;
            ActivityLogger::log(
                'booking.created',
                $user->name . " booked {$court->name} on " . Carbon::parse($booking->date)->format('M j, Y') . ' from ' . Carbon::parse($booking->start_time)->format('g:i A') . ' to ' . Carbon::parse($booking->end_time)->format('g:i A') . '.',
                subject: $booking,
                properties: [
                    'court_id'         => $court->id,
                    'date'             => $booking->date->toDateString(),
                    'start_time'       => $booking->start_time,
                    'end_time'         => $booking->end_time,
                    'amount'           => $booking->amount,
                    'payment_method'   => 'qrph',
                    'discount_percent' => $discountPercent,
                    'given_used'       => $result['paymentReference']->given_used,
                    'reserved_used'    => $result['paymentReference']->reserved_used,
                    'cash_due'         => $result['paymentReference']->cash_due,
                ],
            );
        }

        return response()->json([
            'message'       => $isPaid
                ? 'Payment complete with credit.'
                : 'Booking submitted! Waiting for payment confirmation.',
            'bookings'      => collect($bookings)->map(fn ($b) => [
                'booking_id' => $b->id,
                'date'       => $b->date->toDateString(),
                'poll_token' => $b->poll_token,
                'amount'     => $b->amount,
            ]),
            'booking_id'    => $bookings[0]->id,
            'poll_token'    => $bookings[0]->poll_token,
            'expires_at'    => $bookings[0]->expires_at?->toIso8601String(),
            'amount'        => $result['paymentReference']->amount,
            'given_used'    => $result['paymentReference']->given_used,
            'reserved_used' => $result['paymentReference']->reserved_used,
            'cash_due'      => $result['paymentReference']->cash_due,
            'status'        => $isPaid ? 'paid' : 'pending',
            'idempotent'    => $idempotent,
        ]);
    }

    public function profile()
    {
        return view('user.profile.profile', [
            'profileLayout' => 'layouts.user',
            'profileTitle' => 'Profile | Side House',
            'profileStylesheets' => [
                'css/user/dashboard.css',
                'css/user/user-dashboard.css',
                'css/user/profile.css',
            ],
            'profileScripts' => [],
            'profileSecurityView' => 'partials.profile.security.customer',
            'profileUpdateAction' => route('user.profile.update'),
            'profilePageId' => 'profilePage',
            'canEditEmail' => true,
            'canEditUsername' => true,
            'showUsername' => true,
            'showMembership' => true,
            'showDelete' => true,
            'deleteAccountAction' => route('user.profile.destroy'),
            'deleteZoneDescription' => "Permanently delete your account. This can't be undone.",
            'deleteModalTitle' => 'Delete your account?',
            'deleteModalDescription' => 'This permanently deletes your account and cannot be undone.',
            'deleteCancelLabel' => 'Keep my account',
            'deleteSubmitLabel' => 'Yes, delete account',
            'logoutDescription' => "You'll need to sign in again to access your account.",
            'user' => auth()->user(),
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
     * Receipt page for a fully-paid booking made by the signed-in user.
     * Ownership-gated (user_id match) so a guessed booking ID alone
     * cannot pull up someone else's receipt. If the payment is not yet
     * confirmed, we bounce back to the waiting page — same pattern as
     * PaymentReceiptController::show() for guests.
     */
    public function receiptForUser(Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        $paymentReference = $booking->paymentReference;

        if (! $paymentReference || ! $paymentReference->isConfirmed()) {
            return redirect()
                ->route('book.waiting', ['booking' => $booking])
                ->with('status', "Your payment hasn't been confirmed yet.");
        }

        $bookings = $paymentReference->bookings()
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return view('user.bookings.receipt', [
            'paymentReference' => $paymentReference,
            'bookings'         => $bookings,
        ]);
    }

    /**
     * Permanently delete the signed-in user's own account. The typed
     * confirmation phrase is checked here too, not just in the browser —
     * client-side gating is just UX, this is the actual security check.
     */
    public function destroyAccount(Request $request)
    {
        abort_unless(auth()->user()?->role === 'user', 403, 'Privileged accounts cannot delete themselves.');

        $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        if ($request->input('confirmation') !== 'DELETE MY ACCOUNT') {
            return back()->withErrors([
                'confirmation' => 'Type "DELETE MY ACCOUNT" exactly to confirm.',
            ]);
        }

        $user = auth()->user();

        // Only pending bookings get auto-cancelled here — same rule
        // cancelBooking() enforces for the self-service cancel button.
        // A 'paid' booking has confirmed revenue behind it; cancelling it
        // without an actual refund/cancellation-policy decision would
        // make that revenue vanish from reports with no refund record.
        // Those have to go through staff (Admin\BookingController).
        Booking::where('user_id', $user->user_id)
            ->where('status', 'pending')
            ->where('date', '>=', today()->toDateString())
            ->update(['status' => 'cancelled']);

        ActivityLogger::log(
            'account.deleted',
            "{$user->name} deleted their own account.",
            actor: $user,
            subject: $user,
        );

        $multiGuard = config('auth.multi_guard');
        $guard = GuardSessionState::currentGuard();
        if ($multiGuard) {
            Auth::guard($guard)->logout();
            GuardSessionState::forgetGuard($request, $guard);
        } else {
            auth()->logout();
        }

        $user->delete();

        if ($multiGuard) {
            GuardSessionState::regenerateSession($request);
            $loginRoute = $guard === 'customer' ? 'login' : $guard.'.login';

            return redirect()->route($loginRoute);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
