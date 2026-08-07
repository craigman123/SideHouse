<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Equipment;
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
    private const BOOKING_STEP_MINUTES = 60;
    private const MIN_DURATION_HOURS = 1;
    private const MAX_DURATION_HOURS = 8;

    // How long a pending GCash booking holds its slot before
    // ExpireUnconfirmedGcashBookings cancels it. Must stay <=
    // GcashWebhookController::MATCH_WINDOW_MINUTES, or a real payment that
    // lands late could arrive after we've already expired the booking it
    // belongs to.
    public const GCASH_CONFIRM_WINDOW_MINUTES = 15;

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
     * Same shape/logic as User_UserController::availability() — booked
     * time ranges for a court on a date, so the widget can grey out slots.
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

    /**
     * Active equipment plus how many of each are actually free for the
     * chosen date/start/duration, so the rental step can grey out
     * sold-out items instead of just listing raw stock totals.
     */
    public function equipmentAvailability(Request $request)
    {
        $validated = $request->validate([
            'date'       => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration'   => ['required', 'numeric', 'min:0.25', 'max:' . self::MAX_DURATION_HOURS],
        ]);

        $start = Carbon::parse($validated['date'] . ' ' . $validated['start_time']);
        $end   = $start->copy()->addMinutes((int) round($validated['duration'] * 60));

        $equipment = Equipment::where('status', 'active')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'equipment' => $equipment->map(fn ($item) => [
                'id'        => $item->id,
                'name'      => $item->name,
                'category'  => $item->category,
                'price'     => $item->price,
                'available' => $item->availableStock(
                    $validated['date'],
                    $start->format('H:i:s'),
                    $end->format('H:i:s')
                ),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'court_id'       => ['required', 'integer', 'exists:courts,id'],
            'date'           => ['required', 'date', 'after_or_equal:today'],
            'start_time'     => ['required', 'date_format:H:i'],
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

        $start = Carbon::parse($validated['date'] . ' ' . $validated['start_time']);
        $end   = $start->copy()->addMinutes((int) round($validated['duration'] * 60));

        $overnight = self::CLOSE_HOUR <= self::OPEN_HOUR;
        $open  = $start->copy()->setTime(self::OPEN_HOUR, 0);
        $close = $start->copy()->setTime(self::CLOSE_HOUR, 0);

        if ($overnight) {
            $close->addDay();
            if ($start->lt($open)) {
                $open->subDay();
                $close->subDay();
            }
        }

        if (! ($start->gte($open) && $end->lte($close))) {
            return response()->json(['message' => 'That time falls outside operating hours.'], 422);
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
            return response()->json(['message' => 'That slot was just taken. Please pick another time.'], 422);
        }

        // Re-check equipment stock here too — the browser only showed what
        // was available a moment ago; this is the check that actually counts.
        $equipmentLines = collect($validated['equipment'] ?? []);
        $resolvedEquipment = [];

        foreach ($equipmentLines as $line) {
            $item = Equipment::findOrFail($line['id']);
            $available = $item->availableStock(
                $validated['date'],
                $start->format('H:i:s'),
                $end->format('H:i:s')
            );

            if ($line['quantity'] > $available) {
                return response()->json([
                    'message' => "Only {$available} \"{$item->name}\" left for that time slot — please adjust your rental.",
                ], 422);
            }

            $resolvedEquipment[] = ['item' => $item, 'quantity' => $line['quantity']];
        }

        $courtAmount = $court->hourly_rate * $validated['duration'];
        $equipmentAmount = collect($resolvedEquipment)
            ->sum(fn ($line) => $line['item']->price * $line['quantity']);
        $amount = round($courtAmount + $equipmentAmount, 2);

        // Unique per booking so the guest's status/cancel links can't be
        // guessed from the booking id alone.
        do {
            $pollToken = Str::random(40);
        } while (Booking::where('poll_token', $pollToken)->exists());

        $expiresAt = now()->addMinutes(self::GCASH_CONFIRM_WINDOW_MINUTES);

        $booking = DB::transaction(function () use ($validated, $court, $start, $end, $amount, $resolvedEquipment, $guestEmail, $pollToken, $expiresAt) {
            $booking = Booking::create([
                'user_id'        => null,
                'customer_name'  => $validated['guest_name'],
                'contact_number' => $validated['guest_contact'],
                'email'          => $guestEmail,
                'court_id'       => $court->id,
                'date'           => $validated['date'],
                'start_time'     => $start->format('H:i:s'),
                'end_time'       => $end->format('H:i:s'),
                'amount'         => $amount,
                'payment_method' => $validated['payment_method'],
                // Column name is legacy from GCash-only days, but it's a
                // plain varchar used for the guest-entered reference
                // number regardless of which method (GCash, Landbank...)
                // was selected — no need to rename it in the DB.
                'gcash_reference_number' => $validated['payment_reference'],
                'poll_token'     => $pollToken,
                'expires_at'     => $expiresAt,
                'status'         => 'pending',
            ]);

            foreach ($resolvedEquipment as $line) {
                $booking->equipment()->create([
                    'equipment_id' => $line['item']->id,
                    'quantity'     => $line['quantity'],
                    'price_each'   => $line['item']->price,
                ]);
            }

            return $booking;
        });

        return response()->json([
            'message'    => 'Booking submitted! Waiting for GCash to confirm your payment.',
            'booking'    => $booking,
            'booking_id' => $booking->id,
            'poll_token' => $booking->poll_token,
            'expires_at' => $booking->expires_at->toIso8601String(),
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
        }

        return response()->json([
            'status' => $booking->status,
        ]);
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