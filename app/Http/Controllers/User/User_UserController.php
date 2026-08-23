<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\Equipment;
use App\Models\Membership;
use App\Models\PaymentReference as PaymentReferenceModel;
use App\Models\UnmatchedPayment;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use App\Support\PaymentReference;
use App\Support\PaymentWindows;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function createBooking()
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

        return view('user.bookings.book', [
            'userName'       => auth()->user()->name,
            'userPhone'      => auth()->user()->phone_number ?? '',
            'courts'         => $courts,
            'openHour'       => BookingHours::openHour(),
            'closeHour'      => BookingHours::closeHour(),
            'minDuration'    => BookingHours::minDurationHours(),
            'maxDuration'    => BookingHours::maxDurationHours(),
            'stepMinutes'    => BookingHours::stepMinutes(),
            'closedWeekdays' => BookingHours::closedWeekdays(),
            'closureDates'   => $closureDates,
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
     * checkout's waiting modal calls.
     *
     * Only a 'pending' booking can be cancelled here — a 'paid' booking
     * has confirmed revenue behind it, and cancelling it without going
     * through an actual refund/cancellation-policy decision would make
     * that revenue vanish from reports with no refund record anywhere.
     * If a paid booking genuinely needs cancelling, that has to go
     * through staff (Admin\BookingController), not this self-service
     * endpoint.
     *
     * Locked and re-checked under a transaction rather than trusting the
     * route-bound $booking — GcashWebhookController::handleSms() /
     * LandbankWebhookController::handleSms() could confirm this exact
     * booking's payment between the page loading and this click landing,
     * and without the lock a 'pending' check here could pass a beat
     * before the webhook's own update, then still cancel a booking that
     * had just been paid for.
     *
     * Cancels only THIS booking (this one date) — a sibling booking
     * sharing the same payment_reference from the same multi-date
     * checkout is unaffected.
     */
    public function cancelBooking(Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        $result = DB::transaction(function () use ($booking) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return ['ok' => false, 'status' => $locked->status];
            }

            $locked->update(['status' => 'cancelled']);

            return ['ok' => true, 'booking' => $locked];
        });

        if (! $result['ok']) {
            $message = match ($result['status']) {
                'paid' => 'This booking is already paid and confirmed. Cancelling a paid booking requires a refund — please contact us directly.',
                'cancelled' => 'This booking is already cancelled.',
                default => 'This booking can no longer be cancelled.',
            };

            return response()->json(['message' => $message], 422);
        }

        $booking = $result['booking'];

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
            'currentReference'   => $paymentReference->payment_reference ?? '',
            'statusUrl'          => route('book.status', ['booking' => $booking->id]),
            'cancelUrl'          => route('user.bookings.cancel', ['booking' => $booking->id]),
            'updateReferenceUrl' => route('book.update-reference', ['booking' => $booking->id]),
            'bookUrl'            => route('book.index'),
        ]);
    }

    /**
     * Lets the signed-in user fix a typo'd reference number on their own
     * still-pending booking — the equivalent of
     * GuestBookingController::updateReference(), but ownership-gated via
     * auth() instead of a poll_token, since there's a session here. Same
     * locking/re-check/retroactive-match behavior; see that method's
     * docblock for the full reasoning. Updates the SHARED payment_reference
     * row, so every sibling booking from the same multi-date checkout
     * gets the correction (and a match confirms all of them at once).
     */
    public function updateReference(Request $request, Booking $booking)
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:50'],
        ]);

        $result = DB::transaction(function () use ($booking, $validated) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return ['status' => $locked->status, 'already_resolved' => true];
            }

            $paymentReference = PaymentReferenceModel::where('id', $locked->payment_reference_id)
                ->lockForUpdate()
                ->first();

            if ($paymentReference === null) {
                // Shouldn't happen — every booking created by
                // storeBooking() gets one in the same transaction — but
                // fail safe rather than fatal if it ever does.
                return ['status' => $locked->status, 'already_resolved' => true];
            }

            $paymentReference->update(['payment_reference' => $validated['payment_reference']]);

            // Same rule as storeBooking()'s retroactive claim and both
            // webhook controllers: reference number is the only thing
            // that can confirm a match. No "only one candidate at this
            // amount" fallback — see those for why.
            $normalizedRef = PaymentReference::normalize($validated['payment_reference']);
            $claimedPayment = null;

            if ($normalizedRef !== '' && $paymentReference->confirmed_at === null) {
                $claimedPayment = UnmatchedPayment::unmatched()
                    ->where('payment_method', $paymentReference->payment_method)
                    ->where('amount', $paymentReference->amount)
                    ->where('created_at', '>=', now()->subMinutes(PaymentWindows::claimWindowMinutes($paymentReference->payment_method)))
                    ->lockForUpdate()
                    ->get()
                    ->first(fn ($p) => PaymentReference::normalize((string) $p->reference_number) === $normalizedRef);
            }

            if ($claimedPayment) {
                $paymentReference->update(['confirmed_at' => now()]);
                $claimedPayment->update([
                    'matched_payment_reference_id' => $paymentReference->id,
                    'matched_at'                   => now(),
                ]);

                $paymentReference->bookings()->where('status', 'pending')->update([
                    'status'       => 'paid',
                    'confirmed_at' => now(),
                ]);

                $locked->refresh();
            }

            return ['status' => $locked->status];
        });

        if ($result['status'] === 'paid') {
            $booking->refresh();

            ActivityLogger::log(
                'booking.paid',
                sprintf(
                    "%s's payment for %s was confirmed after they corrected their reference number.",
                    auth()->user()->name,
                    $booking->court?->name ?? 'a court',
                ),
                subject: $booking,
                properties: ['amount' => $booking->amount],
            );
        }

        return response()->json([
            'status'  => $result['status'],
            'message' => match (true) {
                $result['status'] === 'paid' => 'Payment matched! Your booking is confirmed.',
                ! empty($result['already_resolved']) => "This booking is no longer pending, so its reference number can't be changed.",
                default => "Reference number updated — we'll keep watching for a match.",
            },
        ], ! empty($result['already_resolved']) ? 422 : 200);
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

        // Spots are looked up by their OWN date (booking_slots.date), not
        // the parent booking's envelope date — matches GuestBookingController
        // so overnight tail slots still show as booked on the next day.
        $slots = \App\Models\BookingSlot::where('date', $validated['date'])
            ->whereHas('booking', function ($q) use ($validated) {
                $q->where('court_id', $validated['court_id'])
                    ->where('status', '!=', 'cancelled');
            })
            ->get(['start_time', 'end_time']);

        $booked = $slots->map(fn ($slot) => [
            'start' => substr($slot->start_time, 0, 5),
            'end'   => substr($slot->end_time, 0, 5),
        ])->all();

        // Legacy bookings with no slot rows fall back to envelope on booking.date.
        $legacyBookings = Booking::where('court_id', $validated['court_id'])
            ->where('date', $validated['date'])
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('slots')
            ->get(['start_time', 'end_time']);

        foreach ($legacyBookings as $booking) {
            $booked[] = [
                'start' => substr($booking->start_time, 0, 5),
                'end'   => substr($booking->end_time, 0, 5),
            ];
        }

        return response()->json(['booked' => $booked]);
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
        ]);

        $stepMinutes = BookingHours::stepMinutes();

        $windows = collect($validated['slots'])->map(function ($key) use ($stepMinutes) {
            $start = Carbon::parse($key);
            $end   = $start->copy()->addMinutes($stepMinutes);
            return [$start->toDateString(), $start->format('H:i:s'), $end->format('H:i:s')];
        });

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
     * all sharing a single PaymentReference. See
     * GuestBookingController::store()'s docblock — this is the same fix,
     * applied to the signed-in flow.
     */
    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'court_id'       => ['required', 'integer', 'exists:courts,id'],
            'date'           => ['required', 'date', 'after_or_equal:today'],
            'slots'          => ['required', 'array', 'min:1', 'max:60'],
            'slots.*'        => ['required', 'date_format:Y-m-d H:i', 'distinct'],
            'payment_method' => ['required', 'in:gcash,maya'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'equipment'            => ['array'],
            'equipment.*.id'       => ['required_with:equipment', 'integer', 'exists:equipment,id'],
            'equipment.*.quantity' => ['required_with:equipment', 'integer', 'min:1', 'max:20'],
            'payment_reference' => ['required', 'string', 'max:50'],
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
        }

        // Apply the signed-in user's active membership discount, if any,
        // to the court portion of the price (not equipment).
        $activeMembership = Membership::with('plan')
            ->where('user_id', auth()->id())
            ->where('status', 'active')
            ->where('expiry_date', '>=', now())
            ->orderByDesc('expiry_date')
            ->first();
        $discountPercent = $activeMembership?->plan?->discount_percent ?? 0;

        $slotPrice = round($court->hourly_rate * ($stepMinutes / 60) * (1 - $discountPercent / 100), 2);
        $totalSlotCount = count($slotWindows);

        $expiresAt = now()->addMinutes(PaymentWindows::BOOKING_EXPIRY_MINUTES);

        try {
            $result = DB::transaction(function () use (
                $validated, $court, $user, $groups, $slotPrice, $totalSlotCount,
                $discountPercent, $expiresAt
            ) {
                // See GuestBookingController::store() for why this lock
                // is needed (closes the double-booking race between the
                // conflict check and the insert below).
                Court::where('id', $court->id)->lockForUpdate()->first();

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

                        $legacyConflict = Booking::where('court_id', $court->id)
                            ->where('date', $dateStr)
                            ->where('status', '!=', 'cancelled')
                            ->whereDoesntHave('slots')
                            ->where('start_time', '<', $slotEnd->format('H:i:s'))
                            ->where('end_time', '>', $slotStart->format('H:i:s'))
                            ->exists();

                        if ($slotConflict || $legacyConflict) {
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

                $courtAmount = $slotPrice * $totalSlotCount;
                $equipmentAmount = collect($resolvedEquipment)
                    ->sum(fn ($line) => $line['item']->price * $line['quantity']);
                $totalAmount = round($courtAmount + $equipmentAmount, 2);

                // A user can pay via the QR code before finishing this
                // form — if that SMS already arrived, the webhook
                // controller couldn't find a matching payment_reference
                // to attach it to yet and parked it as an
                // UnmatchedPayment. Claim it now the same way the webhook
                // itself would've matched it: the user-typed reference
                // number must match the parked payment's real reference
                // number.
                $unmatchedCandidates = UnmatchedPayment::unmatched()
                    ->where('payment_method', $validated['payment_method'])
                    ->where('amount', $totalAmount)
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

                $isPaid = (bool) $claimedPayment;

                $paymentReference = PaymentReferenceModel::create([
                    'payment_reference' => $validated['payment_reference'],
                    'payment_method'    => $validated['payment_method'],
                    'amount'            => $totalAmount,
                    'confirmed_at'      => $isPaid ? now() : null,
                ]);

                if ($claimedPayment) {
                    $claimedPayment->update([
                        'matched_payment_reference_id' => $paymentReference->id,
                        'matched_at'                   => now(),
                    ]);
                }

                $bookings = [];
                $isFirstGroup = true;

                foreach ($groups as $dateStr => $windows) {
                    $envelopeStart = $windows[0][0];
                    $envelopeEnd   = $windows[count($windows) - 1][1];
                    $dateAmount    = round($slotPrice * count($windows), 2);

                    $booking = Booking::create([
                        'user_id'              => auth()->id(),
                        'customer_name'        => $user->name,
                        'contact_number'       => $validated['contact_number'],
                        'email'                => $user->email,
                        'court_id'             => $court->id,
                        'payment_reference_id' => $paymentReference->id,
                        'payment_method'       => $validated['payment_method'],
                        'date'                 => $dateStr,
                        'start_time'           => $envelopeStart->format('H:i:s'),
                        'end_time'             => $envelopeEnd->format('H:i:s'),
                        // See GuestBookingController::store() for why
                        // equipment cost lands entirely on the first date.
                        'amount'               => $isFirstGroup ? round($dateAmount + ($totalAmount - $courtAmount), 2) : $dateAmount,
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
                            'date'       => $start->toDateString(),
                            'start_time' => $start->format('H:i:s'),
                            'end_time'   => $end->format('H:i:s'),
                            'price'      => $slotPrice,
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

        foreach ($bookings as $booking) {
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
                    'payment_method'   => $validated['payment_method'],
                    'discount_percent' => $discountPercent,
                ],
            );
        }

        return response()->json([
            'message'    => $isPaid
                ? 'Booking confirmed! We matched it to a payment that already came in.'
                : 'Booking submitted! Waiting for payment confirmation.',
            'bookings'   => collect($bookings)->map(fn ($b) => [
                'booking_id' => $b->id,
                'date'       => $b->date->toDateString(),
                'amount'     => $b->amount,
            ]),
            // Backwards-compatible top-level fields mirroring the FIRST
            // booking, for any older frontend code that hasn't been
            // updated yet to read the `bookings` array above.
            'booking_id' => $bookings[0]->id,
            'expires_at' => $bookings[0]->expires_at?->toIso8601String(),
            'amount'     => $result['paymentReference']->amount,
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

        // Only pending bookings get auto-cancelled here — same rule
        // cancelBooking() enforces for the self-service cancel button.
        // A 'paid' booking has confirmed revenue behind it; cancelling it
        // without an actual refund/cancellation-policy decision would
        // make that revenue vanish from reports with no refund record.
        // Those have to go through staff (Admin\BookingController).
        Booking::where('user_id', $user->user_id)
            ->where('status', 'pending')
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
