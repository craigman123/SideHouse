<?php

namespace App\Http\Controllers\Guest;

use App\Support\PaymentWindows;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Court;
use App\Models\Equipment;
use App\Models\UnmatchedPayment;
use App\Support\ActivityLogger;
use App\Support\PaymentReference;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GuestBookingController extends Controller
{
    // Deliberately duplicated from User_UserController rather than shared —
    // guest booking has different validation and can never call auth(),
    // so keeping it fully separate avoids accidentally coupling the two.
    // If OPEN_HOUR/CLOSE_HOUR/etc. change, update both places.
    private const OPEN_HOUR = 16;
    private const CLOSE_HOUR = 7;
    private const BOOKING_STEP_MINUTES = 30;
    private const MIN_DURATION_HOURS = 1;
    private const MAX_DURATION_HOURS = 10;

    public function landing()
    {
        $courts = Court::where('status', 'active')->orderBy('name')->get();

        return view('landing', [
            'courts'      => $courts,
            'openHour'    => self::OPEN_HOUR,
            'closeHour'   => self::CLOSE_HOUR,
            'minDuration' => self::MIN_DURATION_HOURS,
            'maxDuration' => self::MAX_DURATION_HOURS,
            'stepMinutes' => self::BOOKING_STEP_MINUTES,
        ]);
    }

    /**
     * Hours booked per day for the current month, for the landing page's
     * "Court Usage This Month" bar chart. Hours are attributed to the
     * booking's `date` (its start date) even when a slot runs past
     * midnight, so an overnight booking doesn't get split across two bars.
     */
    public function monthlyStats()
    {
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        $bookings = Booking::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->get(['date', 'start_time', 'end_time']);

        $hoursByDate = [];
        $bookingsByDate = [];

        foreach ($bookings as $booking) {
            $dateStr = Carbon::parse($booking->date)->toDateString();

            $start = Carbon::parse($dateStr . ' ' . $booking->start_time);
            $end   = Carbon::parse($dateStr . ' ' . $booking->end_time);

            // Overnight booking (e.g. starts 22:00, ends 02:00) — end_time
            // is earlier than start_time on the same stored date, so push
            // it to the next calendar day to get the real duration.
            if ($end->lte($start)) {
                $end->addDay();
            }

            $hoursByDate[$dateStr] = ($hoursByDate[$dateStr] ?? 0) + ($start->diffInMinutes($end) / 60);
            $bookingsByDate[$dateStr] = ($bookingsByDate[$dateStr] ?? 0) + 1;
        }

        $days = [];
        for ($date = $monthStart->copy(); $date->lte($monthEnd); $date->addDay()) {
            $dateStr = $date->toDateString();
            $days[] = [
                'day'      => $date->day,
                'date'     => $dateStr,
                'hours'    => round($hoursByDate[$dateStr] ?? 0, 2),
                'bookings' => $bookingsByDate[$dateStr] ?? 0,
            ];
        }

        return response()->json([
            'month_label' => $monthStart->format('F Y'),
            'days'        => $days,
        ]);
    }

    /**
     * Same shape as User_UserController::availability() at the top level
     * (a flat list of booked ranges), but the guest widget now books
     * individual hours rather than one continuous range, so a booking's
     * *actual* reserved hours (its booking_slots rows) are returned
     * instead of its full start-to-end envelope — otherwise a booking
     * spanning 4–5 PM and 9–10 PM would incorrectly grey out the empty
     * 5–9 PM gap for everyone else too. Older duration-only bookings
     * (no slot rows) fall back to their envelope, same as before.
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
     * every hour the guest has selected so far, so the rental step can
     * grey out sold-out items instead of just listing raw stock totals.
     * Selected hours no longer have to be contiguous, so this takes the
     * *minimum* available count across all of them — the item needs to
     * be free for each hour requested, not just one.
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'court_id'       => ['required', 'integer', 'exists:courts,id'],
            'date'           => ['required', 'date', 'after_or_equal:today'],
            // Individual selected hours instead of a single start_time +
            // duration — no longer required to be contiguous (e.g. 4–5 PM
            // and 9–10 PM in the same booking is valid).
            'slots'          => [
                'required',
                'array',
                'min:' . self::MIN_DURATION_HOURS,
                'max:' . self::MAX_DURATION_HOURS,
            ],
            'slots.*'        => ['required', 'date_format:H:i', 'distinct'],
            // GCash and Landbank are the guest payment methods — the QR
            // code / account number is static (same one for every
            // booking), so nothing here proves payment on its own.
            // GcashWebhookController / LandbankWebhookController are what
            // actually confirm it, by matching the amount (and the
            // reference number below, when there's more than one
            // same-amount booking pending at once) against the SMS receipt
            // for whichever method was selected.
            'payment_method' => ['required', 'in:gcash,landbank'],
            'guest_name'     => ['required', 'string', 'max:255'],
            // Loose on purpose (7–30 digits/spaces/dashes/parens/+) since
            // this needs to accept PH mobile numbers in several common
            // formats without being a full phone-format validator.
            'guest_contact'  => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            // Raw JWT from Google Identity Services — verified against
            // Google's tokeninfo endpoint below, never trusted as-is.
            'google_id_token' => ['required', 'string'],
            'equipment'            => ['array'],
            'equipment.*.id'       => ['required_with:equipment', 'integer', 'exists:equipment,id'],
            'equipment.*.quantity' => ['required_with:equipment', 'integer', 'min:1', 'max:20'],
            // Guest-entered, so never trusted on its own — it's only used
            // to disambiguate when two pending bookings share the exact
            // same amount at the same time. The matching webhook
            // controller for the selected payment_method is the only
            // thing that actually confirms payment, from the real SMS
            // receipt.
            'payment_reference' => ['required', 'string', 'max:50'],
        ]);

        $guestEmail = $this->verifyGoogleIdToken($validated['google_id_token']);
        if ($guestEmail === null) {
            return response()->json([
                'message' => "We couldn't verify that Google sign-in. Please sign in again.",
            ], 422);
        }

        $court = Court::findOrFail($validated['court_id']);

        // Same overnight-wrap handling as before (OPEN_HOUR can be later
        // in the clock than CLOSE_HOUR, e.g. 4 PM to 7 AM), just applied
        // per selected hour now instead of to a single start/end pair.
        $overnight = self::CLOSE_HOUR <= self::OPEN_HOUR;
        $open  = Carbon::parse($validated['date'])->setTime(self::OPEN_HOUR, 0);
        $close = Carbon::parse($validated['date'])->setTime(self::CLOSE_HOUR, 0);
        if ($overnight) {
            $close->addDay();
        }

        $slotWindows = [];
        foreach ($validated['slots'] as $time) {
            $start = Carbon::parse($validated['date'] . ' ' . $time);

            // A clock time earlier than OPEN_HOUR belongs to the tail end
            // of the previous night's window (e.g. 2 AM when OPEN_HOUR is
            // 4 PM) — push it a day forward so it lands inside [$open, $close].
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

        // Sort chronologically so the envelope stored on the booking row
        // itself (still date/start_time/end_time, for the admin dashboard
        // and stats chart) reflects the true earliest-to-latest range even
        // when the selected hours wrap past midnight.
        usort($slotWindows, fn ($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);
        $envelopeStart = $slotWindows[0][0];
        $envelopeEnd   = $slotWindows[count($slotWindows) - 1][1];

        $slotPrice = $court->hourly_rate * (self::BOOKING_STEP_MINUTES / 60);

        // Unique per booking so the guest's status/cancel links can't be
        // guessed from the booking id alone.
        do {
            $pollToken = Str::random(40);
        } while (Booking::where('poll_token', $pollToken)->exists());

        $expiresAt = now()->addMinutes(PaymentWindows::BOOKING_EXPIRY_MINUTES);

        try {
            $booking = DB::transaction(function () use ($validated, $court, $envelopeStart, $envelopeEnd, $slotWindows, $slotPrice, $guestEmail, $pollToken, $expiresAt) {
                // Lock the court row for the rest of this transaction.
                // There's nothing to lock on the *slot* itself yet — it
                // may not have any rows at all — so this locks the court
                // instead: a second concurrent booking attempt for the
                // same court has to wait right here until this whole
                // check-then-insert sequence commits or rolls back.
                // Without this, two guests confirming the same 2 PM slot
                // within milliseconds of each other could both pass the
                // conflict check below before either one's insert lands,
                // and both would succeed — a real double-booking neither
                // of them would see until it's too late.
                Court::where('id', $court->id)->lockForUpdate()->first();

                foreach ($slotWindows as [$slotStart, $slotEnd]) {
                    $slotConflict = BookingSlot::whereHas('booking', function ($q) use ($court, $validated) {
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
                        // Caught just below — turned into the same 422
                        // the guest would've gotten if this had been
                        // caught before the transaction even started.
                        throw new \RuntimeException('That slot was just taken. Please pick another time.');
                    }
                }

                // Re-check equipment stock inside the same lock — the
                // browser only showed what was available a moment ago;
                // this is the check that actually counts. An item has to
                // be free for every selected hour, not just one, so take
                // the minimum available across all of them.
                $equipmentLines = collect($validated['equipment'] ?? []);
                $resolvedEquipment = [];

                foreach ($equipmentLines as $line) {
                    // Locked directly (not just findOrFail) so the stock
                    // check below stays race-safe on its own — right now
                    // there's only one court, so the court lock above
                    // already happens to serialize this too, but that
                    // protection would silently disappear the moment a
                    // second court exists, since equipment isn't scoped
                    // to a court. Locking the equipment row itself makes
                    // this correct regardless of how many courts there are.
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

                // A guest can pay via the QR code before finishing this
                // form — if that SMS already arrived, GcashWebhookController
                // (or LandbankWebhookController) couldn't find a pending
                // booking to attach it to yet and parked it as an
                // UnmatchedPayment instead. Claim it now, retroactively,
                // the same way the webhook itself would've matched it:
                // require the guest-typed reference number to match the
                // parked payment's real reference number. There is
                // deliberately no "only one candidate, so it must be
                // them" fallback — amount alone (court pricing is round
                // numbers, so same-amount collisions are common) is not
                // proof of payment, and without a hard reference-number
                // requirement a guest who types a made-up reference could
                // race a real payer and get their booking marked paid
                // using someone else's payment before that payer even
                // finishes the form. A guest who mistyped their own real
                // reference number just stays pending and needs staff to
                // resolve it manually — worse UX, but a typo is
                // recoverable and a stolen payment is not.
                //
                // lockForUpdate() here for the same reason as the
                // equipment lock above — without it, two concurrent
                // store() calls with the same amount could both claim the
                // same parked payment.
                $unmatchedCandidates = UnmatchedPayment::unmatched()
                    ->where('payment_method', $validated['payment_method'])
                    ->where('amount', $amount)
                    ->where('created_at', '>=', now()->subMinutes(PaymentWindows::claimWindowMinutes($validated['payment_method'])))
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get();

                $claimedPayment = null;
                if ($unmatchedCandidates->isNotEmpty()) {
                    $normalizedGuestRef = PaymentReference::normalize($validated['payment_reference']);

                    $claimedPayment = $normalizedGuestRef !== ''
                        ? $unmatchedCandidates->first(function ($p) use ($normalizedGuestRef) {
                            return PaymentReference::normalize((string) $p->reference_number) === $normalizedGuestRef;
                        })
                        : null;
                }

                $booking = Booking::create([
                    'user_id'        => null,
                    'customer_name'  => $validated['guest_name'],
                    'contact_number' => $validated['guest_contact'],
                    'email'          => $guestEmail,
                    'court_id'       => $court->id,
                    'date'           => $validated['date'],
                    'start_time'     => $envelopeStart->format('H:i:s'),
                    'end_time'       => $envelopeEnd->format('H:i:s'),
                    'amount'         => $amount,
                    'payment_method' => $validated['payment_method'],
                    // Column name is legacy from GCash-only days, but
                    // it's a plain varchar used for the guest-entered
                    // reference number regardless of which method
                    // (GCash, Landbank...) was selected — no need to
                    // rename it in the DB.
                    'gcash_reference_number' => $validated['payment_reference'],
                    'poll_token'     => $pollToken,
                    'expires_at'     => $expiresAt,
                    // If a parked payment was just claimed, this booking
                    // is paid the instant it's created — no need to
                    // wait on a webhook that already fired and isn't
                    // coming again for this transfer.
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

        // Logged after the transaction commits, not inside it — a booking
        // that gets rolled back (slot conflict, stock conflict, etc.)
        // should never leave a log entry behind. actor is explicitly null
        // (never auth()->user()) since this is always a guest, unauthenticated
        // action; subject is the booking itself so it shows up as
        // "Booking #<id>" in the admin activity log.
        ActivityLogger::log(
            'booking.created',
            sprintf(
                '%s booked %s on %s from %s to %s.',
                $booking->customer_name,
                $court->name,
                Carbon::parse($booking->date)->format('M d, Y'),
                Carbon::parse($booking->start_time)->format('g:i A'),
                Carbon::parse($booking->end_time)->format('g:i A'),
            ),
            actor: null,
            subject: $booking,
            properties: [
                'payment_method' => $booking->payment_method,
                'amount'         => $booking->amount,
                'status'         => $booking->status,
            ],
        );

        // The booking can already be 'paid' at creation time if it just
        // claimed a payment that arrived (and got parked as an
        // UnmatchedPayment) before the guest finished this form — see the
        // $claimedPayment logic above. Worth its own log line since "paid
        // the instant it was created" is a distinct, useful thing to see
        // in the audit trail, separate from "booked".
        if ($booking->status === 'paid') {
            ActivityLogger::log(
                'booking.paid',
                sprintf(
                    "%s's payment for %s was confirmed automatically (matched an existing %s payment).",
                    $booking->customer_name,
                    $court->name,
                    ucfirst($booking->payment_method),
                ),
                actor: null,
                subject: $booking,
                properties: ['amount' => $booking->amount],
            );
        }

        return response()->json([
            'message'    => $booking->status === 'paid'
                ? 'Booking confirmed! We matched it to a payment that already came in.'
                : 'Booking submitted! Waiting for GCash to confirm your payment.',
            'booking'    => $booking,
            'booking_id' => $booking->id,
            'poll_token' => $booking->poll_token,
            'expires_at' => $booking->expires_at?->toIso8601String(),
            'amount'     => $booking->amount,
        ]);
    }

    /**
     * Lets the guest's own browser poll for the outcome of their pending
     * GCash booking, without needing an account or session — the
     * poll_token generated at booking time (and handed back in the store()
     * response) stands in for auth here.
     */
    public function status(Request $request, Booking $booking)
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $booking->poll_token || ! hash_equals($booking->poll_token, $token)) {
            abort(403);
        }

        return response()->json([
            'status' => $booking->status,
        ]);
    }

    /**
     * Lets the guest give up on a pending GCash payment and release the
     * slot early, instead of waiting out the full confirmation window.
     * Only works while the booking is still pending — once GCash confirms
     * it (or it's already cancelled/expired) this is a no-op.
     */
    public function cancel(Request $request, Booking $booking)
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $booking->poll_token || ! hash_equals($booking->poll_token, $token)) {
            abort(403);
        }

        if ($booking->status === 'pending') {
            $booking->update(['status' => 'cancelled']);

            ActivityLogger::log(
                'booking.cancelled',
                sprintf(
                    '%s cancelled a booking for %s on %s.',
                    $booking->customer_name,
                    $booking->court?->name ?? 'a court',
                    Carbon::parse($booking->date)->format('M d, Y'),
                ),
                actor: null,
                subject: $booking,
            );
        }

        return response()->json([
            'status' => $booking->status,
        ]);
    }

    /**
     * Lets the guest fix a typo'd reference number on their own still-
     * pending booking, instead of the only options being "wait for it to
     * expire" or "cancel and start the whole booking over" — the slot
     * and everything else about the booking stays exactly as-is, only
     * the reference number (and the retroactive payment match it
     * unlocks) changes. A no-op once the booking is already
     * paid/cancelled/expired — see the already_resolved branch below.
     */
    public function updateReference(Request $request, Booking $booking)
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $booking->poll_token || ! hash_equals($booking->poll_token, $token)) {
            abort(403);
        }

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:50'],
        ]);

        $result = DB::transaction(function () use ($booking, $validated) {
            // Re-fetch under lockForUpdate() rather than trusting the
            // route-bound $booking — same reasoning as the webhook
            // controllers' status re-check: without this, a webhook
            // confirming (or ExpireUnconfirmedGcashBookings/
            // ExpireUnconfirmedLandbankBookings cancelling) this exact
            // booking between the check below and the update at the
            // bottom could get silently clobbered by this request, or
            // vice versa.
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($booking->status !== 'pending') {
                return ['status' => $booking->status, 'already_resolved' => true];
            }

            $booking->update(['gcash_reference_number' => $validated['payment_reference']]);

            // Same rule as store()'s retroactive claim and both webhook
            // controllers: reference number is the only thing that can
            // confirm a match. No "only one candidate at this amount"
            // fallback — see those three for why.
            $normalizedRef = PaymentReference::normalize($validated['payment_reference']);
            $claimedPayment = null;

            if ($normalizedRef !== '') {
                $claimedPayment = UnmatchedPayment::unmatched()
                    ->where('payment_method', $booking->payment_method)
                    ->where('amount', $booking->amount)
                    ->where('created_at', '>=', now()->subMinutes(PaymentWindows::claimWindowMinutes($booking->payment_method)))
                    ->lockForUpdate()
                    ->get()
                    ->first(fn ($p) => PaymentReference::normalize((string) $p->reference_number) === $normalizedRef);
            }

            if ($claimedPayment) {
                $booking->update(['status' => 'paid', 'confirmed_at' => now()]);
                $claimedPayment->update(['matched_booking_id' => $booking->id, 'matched_at' => now()]);
            }

            return ['status' => $booking->status];
        });

        if ($result['status'] === 'paid') {
            $booking->refresh();

            ActivityLogger::log(
                'booking.paid',
                sprintf(
                    "%s's payment for %s was confirmed after they corrected their reference number.",
                    $booking->customer_name,
                    $booking->court?->name ?? 'a court',
                ),
                actor: null,
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
     * Verifies a Google Identity Services ID token against Google's
     * tokeninfo endpoint and returns the verified, Google-owned email
     * address — or null if the token is missing, expired, unverified,
     * or wasn't issued for this site's OAuth client.
     *
     * Uses the tokeninfo endpoint (rather than a JWKS/signature library)
     * to avoid adding a dependency; it's an extra network round trip per
     * booking, which is fine at guest-booking volume. If that ever
     * becomes a bottleneck, swap this for google/apiclient's
     * Google_Client::verifyIdToken(), which checks the signature locally.
     */
    private function verifyGoogleIdToken(string $idToken): ?string
    {
        $clientId = config('services.google.client_id');
        if (! $clientId) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $claims = $response->json();

        if (! is_array($claims) || empty($claims['email']) || empty($claims['aud'])) {
            return null;
        }

        // aud must match our OAuth client — otherwise this is a token
        // issued for a completely different Google app.
        if (! hash_equals($clientId, (string) $claims['aud'])) {
            return null;
        }

        // Google sends this as the string "true"/"false", not a boolean.
        $emailVerified = ($claims['email_verified'] ?? 'false') === 'true'
            || ($claims['email_verified'] ?? false) === true;

        if (! $emailVerified) {
            return null;
        }

        return $claims['email'];
    }
}