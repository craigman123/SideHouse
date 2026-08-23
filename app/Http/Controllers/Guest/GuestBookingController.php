<?php

namespace App\Http\Controllers\Guest;

use App\Support\PaymentWindows;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\Equipment;
use App\Models\PaymentReference as PaymentReferenceModel;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GuestBookingController extends Controller
{
    // Opening/closing hours, slot length, and min/max booking duration
    // now live in the business_settings table (see App\Support\BookingHours
    // and Admin\ScheduleController) instead of being hardcoded here.
    // User_UserController should switch to the same BookingHours calls
    // rather than keeping its own separate copy of these values.

    public function landing()
    {
        $courts = Court::where('status', 'active')->orderBy('name')->get();

        // The guest widget only ever books the first active court (see
        // initBooking() in guest-book.js), so the calendar only needs
        // closures that apply to that one court — either scoped to it
        // directly, or store-wide (court_id null).
        $primaryCourtId = $courts->first()?->id;

        $closureDates = CourtClosure::upcoming()
            ->where(function ($query) use ($primaryCourtId) {
                $query->whereNull('court_id');
                if ($primaryCourtId) {
                    $query->orWhere('court_id', $primaryCourtId);
                }
            })
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->values();

        return view('landing', [
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

        // Slots are looked up by their OWN date now, not the parent
        // booking's envelope date — a tail slot from an overnight booking
        // that started the day before (e.g. a 6 AM slot booked as part of
        // "yesterday's" 4 PM–close session) still needs to show as booked
        // when a guest checks today's availability, even though the
        // parent Booking's own `date` column is yesterday.
        $slots = BookingSlot::where('date', $validated['date'])
            ->whereHas('booking', function ($q) use ($validated) {
                $q->where('court_id', $validated['court_id'])
                    ->where('status', '!=', 'cancelled');
            })
            ->get(['start_time', 'end_time']);

        $booked = $slots->map(fn ($slot) => [
            'start' => substr($slot->start_time, 0, 5),
            'end'   => substr($slot->end_time, 0, 5),
        ])->all();

        // Legacy bookings made before booking_slots existed have no slot
        // rows at all — fall back to their envelope on booking.date,
        // same as before. These predate the overnight-rollover bug fix
        // entirely, so their date is whatever was originally (possibly
        // incorrectly) stored.
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

        // Lets the picker show "Closed" for a date instead of just an
        // empty/fully-available slot list — covers both a one-off
        // CourtClosure row and a recurring weekly closure (see
        // App\Support\BookingHours and the admin Schedule page).
        $courtId = (int) $validated['court_id'];

        return response()->json([
            'booked' => $booked,
            'closed' => BookingHours::isClosed($courtId, $validated['date']),
            'closed_reason' => BookingHours::closedReason($courtId, $validated['date']),
        ]);
    }

    /**
     * Active equipment plus how many of each are actually free across
     * every hour the guest has selected so far, so the rental step can
     * grey out sold-out items instead of just listing raw stock totals.
     * Selected hours no longer have to be contiguous OR on the same
     * date, so this takes the *minimum* available count across every
     * one of them — the item needs to be free for each real date+hour
     * requested, not just one.
     *
     * `slots.*` is now "Y-m-d H:i" (real date + time), matching store()
     * — see that method's docblock for why.
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
     * Backs the landing page's "My Bookings" search tab — lets a guest
     * look up their own bookings by the phone number or email they
     * booked with, without an account or session. Loose digit-only
     * matching on contact_number (guests type it in all kinds of
     * formats: spaces, dashes, +63 vs 0-prefix) and a case-insensitive
     * match on email, since those are the only two things a guest has
     * to identify themselves with here.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = trim((string) ($validated['phone'] ?? ''));
        $email = trim((string) ($validated['email'] ?? ''));
        $digits = preg_replace('/\D/', '', $phone);

        // Same readiness bar the frontend (landing-search.js) enforces
        // before it even fires the request — re-checked here so a
        // half-typed digit string or a bare '@' can't return the whole
        // table if this endpoint is ever hit directly. Neither field
        // being usable means there's nothing to search on at all —
        // returning early here also avoids building an empty where()
        // closure below, which would otherwise put no constraint on the
        // query and match every booking in the system.
        $phoneReady = strlen($digits) >= 7;
        $emailReady = $email !== '' && str_contains($email, '@');

        if (! $phoneReady && ! $emailReady) {
            return response()->json(['bookings' => []]);
        }

        $bookings = Booking::with(['court', 'equipment', 'paymentReference'])
            ->where(function ($q) use ($phoneReady, $emailReady, $email, $digits) {
                // Both fields can be filled in at once — treated as "match
                // either", not "match both", since a guest might only
                // remember one of the two correctly.
                if ($emailReady) {
                    $q->orWhere('email', 'ILIKE', $email);
                }
                if ($phoneReady) {
                    $q->orWhereRaw("regexp_replace(contact_number, '\\D', '', 'g') LIKE ?", ['%' . $digits . '%']);
                }
            })
            ->orderByDesc('date')
            ->orderByDesc('start_time')
            ->limit(20)
            ->get();

        // Resolved by id rather than through a relation on the
        // booking_equipment rows themselves (e.g. $line->equipmentItem)
        // since that relation's exact name isn't something this method
        // otherwise depends on — a plain id-to-name lookup stays correct
        // no matter what that relation is called or whether it exists.
        $equipmentNames = Equipment::whereIn(
            'id',
            $bookings->flatMap(fn ($b) => $b->equipment->pluck('equipment_id'))->unique()
        )->pluck('name', 'id');

        return response()->json([
            'bookings' => $bookings->map(fn ($booking) => [
                'court'      => $booking->court?->name ?? 'Court',
                'date'       => Carbon::parse($booking->date)->format('M d, Y'),
                'time'       => Carbon::parse($booking->start_time)->format('g:i A') . ' – ' . Carbon::parse($booking->end_time)->format('g:i A'),
                'status'     => $booking->status,
                'amount'     => (float) $booking->amount,
                'payment'    => $booking->paymentReference?->payment_method ?? $booking->payment_method,
                'reference'  => $this->maskReference($booking->paymentReference?->gcash_reference_number ?? $booking->gcash_reference_number),
                'equipment'  => $booking->equipment->map(fn ($line) => [
                    'name'     => $equipmentNames[$line->equipment_id] ?? 'Item',
                    'quantity' => $line->quantity,
                ])->values(),
            ]),
        ]);
    }

    /**
     * Partially masks a payment reference for the guest-facing "Find Your
     * Booking" lookup — this endpoint needs no login (just a phone number
     * or email), so the full reference shouldn't be handed back over the
     * wire. Uses a fixed-length mask rather than one sized to the input,
     * so the asterisks don't themselves leak how long the real reference
     * is.
     */
    private function maskReference(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return $reference;
        }

        $length = strlen($reference);

        if ($length <= 6) {
            return substr($reference, 0, 1) . str_repeat('*', max($length - 1, 0));
        }

        return substr($reference, 0, 4) . '***' . substr($reference, -2);
    }

    /**
     * Turns a flat list of "Y-m-d H:i" slot keys into groups keyed by
     * distinct calendar date, each holding that date's [start, end]
     * Carbon window pairs sorted in order — and validates every slot
     * along the way (falls within operating hours for its real or
     * overnight-rollover session, and each date's slot count is within
     * the configured min/max duration and isn't closed). Shared by
     * store() (which needs this to actually create the bookings) and
     * paymentPage() (which needs the same validated shape purely to
     * render an accurate summary before anything is created) so the two
     * can never drift out of sync on what counts as a valid selection.
     *
     * Throws RuntimeException with a guest-facing message on any
     * validation failure — callers decide how to surface that (a 422
     * JSON response from store(), a redirect with a flash error from
     * paymentPage()).
     *
     * @param  array<string>  $slotKeys  Each "Y-m-d H:i", as sent by the client.
     * @return array<string, array<array{0: Carbon, 1: Carbon}>>
     */
    private function resolveSlotGroups(int $courtId, array $slotKeys): array
    {
        $openHour    = BookingHours::openHour();
        $closeHour   = BookingHours::closeHour();
        $stepMinutes = BookingHours::stepMinutes();
        $overnight   = $closeHour <= $openHour;

        // Every slot's real calendar date + time, parsed straight from
        // what the frontend sent — no more guessing which direction to
        // roll a bare time, since the true date already came from the
        // client (book.js/guest-book.js pick it directly off the
        // calendar day the guest clicked).
        $slotWindows = [];
        foreach ($slotKeys as $key) {
            $start = Carbon::createFromFormat('Y-m-d H:i', $key);
            $end   = $start->copy()->addMinutes($stepMinutes);

            // The top-level `date` field is only validated as
            // today-or-later — it's a fallback label, not authoritative
            // (see this method's docblock). Each slot carries its own
            // real calendar date, so each one needs its own past-date
            // check too, or a request with a future `date` but a
            // crafted past `slots.*` entry would sail through the
            // operating-hours check below and book an already-elapsed
            // hour.
            if ($start->lt(now())) {
                throw new \RuntimeException("The {$start->format('M j, Y g:i A')} slot has already passed.");
            }

            // A slot can legitimately belong to either the session that
            // opens ON its own calendar date, or — for an overnight
            // court only — the tail end of the session that opened the
            // day before and runs past midnight into it.
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
                throw new \RuntimeException("The {$start->format('M j, Y g:i A')} slot falls outside operating hours.");
            }

            $slotWindows[] = [$start, $end];
        }

        usort($slotWindows, fn ($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);

        // One group per distinct real calendar date — this is what
        // becomes one Booking row each, all sharing one payment.
        $groups = [];
        foreach ($slotWindows as $window) {
            $groups[$window[0]->toDateString()][] = $window;
        }
        ksort($groups);

        // Same min/max duration rule as before, just checked per date
        // now instead of against the whole multi-date selection.
        $minSlotsPerDate = max(1, (int) ceil(BookingHours::minDurationHours() * 60 / $stepMinutes));
        $maxSlotsPerDate = max(1, (int) floor(BookingHours::maxDurationHours() * 60 / $stepMinutes));

        foreach ($groups as $dateStr => $windows) {
            if (count($windows) < $minSlotsPerDate || count($windows) > $maxSlotsPerDate) {
                throw new \RuntimeException(
                    "Please select between {$minSlotsPerDate} and {$maxSlotsPerDate} hour(s) for "
                        . Carbon::parse($dateStr)->format('M j, Y') . '.'
                );
            }

            if (BookingHours::isClosed($courtId, $dateStr)) {
                throw new \RuntimeException('This court is closed on ' . Carbon::parse($dateStr)->format('M j, Y') . '.');
            }
        }

        return $groups;
    }

    /**
     * Full-page replacement for the old "Almost Done" modal (guest info +
     * payment method + Confirm) — reached from the equipment step's
     * Continue button instead of opening a modal, so refreshing this page
     * (or a stray backdrop click, back before this) can't silently wipe
     * out the guest's date/time/equipment picks the way the old modal's
     * click-outside-to-close could. Nothing is persisted server-side yet
     * at this point — the guest's picks travel here as query params and
     * go straight back out in the page's own form; store() below is still
     * the only place a Booking actually gets created.
     *
     * Re-validates the slot selection with the exact same rules store()
     * enforces (via resolveSlotGroups()) purely so this page can render
     * an accurate summary/total — it is NOT the source of truth for
     * conflicts or stock, both of which store() re-checks itself under
     * lockForUpdate() at final submit. A guest can still land here with a
     * technically valid selection that someone else grabs a moment later;
     * that's handled the same way it always was, by store() returning a
     * 422 and the page's script bouncing back to the calendar.
     */
    public function paymentPage(Request $request)
    {
        $validated = $request->validate([
            'court_id'             => ['required', 'integer', 'exists:courts,id'],
            'date'                 => ['required', 'date'],
            'slots'                => ['required', 'array', 'min:1'],
            'slots.*'              => ['required', 'date_format:Y-m-d H:i', 'distinct'],
            'equipment'            => ['array'],
            'equipment.*.id'       => ['required_with:equipment', 'integer', 'exists:equipment,id'],
            'equipment.*.quantity' => ['required_with:equipment', 'integer', 'min:1', 'max:20'],
        ]);

        $court = Court::findOrFail($validated['court_id']);

        try {
            $groups = $this->resolveSlotGroups((int) $validated['court_id'], $validated['slots']);
        } catch (\RuntimeException $e) {
            return redirect()->route('landing')->with('error', $e->getMessage());
        }

        $stepMinutes    = BookingHours::stepMinutes();
        $slotPrice      = $court->hourly_rate * ($stepMinutes / 60);
        $totalSlotCount = collect($groups)->sum(fn ($windows) => count($windows));
        $courtAmount    = round($slotPrice * $totalSlotCount, 2);

        // Resolved purely for display (name/price/subtotal) — quantities
        // aren't re-checked against live stock here on purpose; see the
        // docblock above for why store() staying the sole authority is
        // fine.
        $equipmentLines = collect($validated['equipment'] ?? []);
        $catalog = $equipmentLines->isNotEmpty()
            ? Equipment::whereIn('id', $equipmentLines->pluck('id'))->get()->keyBy('id')
            : collect();

        $resolvedEquipment = $equipmentLines
            ->map(function ($line) use ($catalog) {
                $item = $catalog->get($line['id']);
                if (! $item) {
                    return null;
                }

                return [
                    'id'       => $item->id,
                    'name'     => $item->name,
                    'price'    => (float) $item->price,
                    'quantity' => (int) $line['quantity'],
                    'subtotal' => round($item->price * $line['quantity'], 2),
                ];
            })
            ->filter()
            ->values();

        $equipmentAmount = round($resolvedEquipment->sum('subtotal'), 2);
        $totalAmount     = round($courtAmount + $equipmentAmount, 2);

        $dateSummaries = [];
        foreach ($groups as $dateStr => $windows) {
            $start = $windows[0][0];
            $end   = $windows[count($windows) - 1][1];
            $dateSummaries[] = Carbon::parse($dateStr)->format('M j, Y') . ', '
                . $start->format('g:i A') . '–' . $end->format('g:i A');
        }

        return view('guest_payment', [
            'court'              => $court,
            'courtId'            => $court->id,
            'date'               => $validated['date'],
            'slots'              => $validated['slots'],
            'dateSummaries'      => $dateSummaries,
            'equipmentLines'     => $resolvedEquipment,
            'courtAmount'        => $courtAmount,
            'equipmentAmount'    => $equipmentAmount,
            'totalAmount'        => $totalAmount,
            'paymentHoldMinutes' => PaymentWindows::BOOKING_EXPIRY_MINUTES,
            'storeUrl'           => route('guest.book.store'),
            'waitingUrlTemplate' => route('guest.book.waiting', ['booking' => '__ID__']),
            'landingUrl'         => route('landing'),
            'googleClientId'     => config('services.google.client_id'),
        ]);
    }

    /**
     * Creates one Booking per distinct calendar date the guest selected,
     * all sharing a single PaymentReference — a guest who books Aug 19
     * and Aug 20 in the same checkout pays once, and gets 2 Booking rows
     * (each with its own booking_slots and its own reminder_sent_at) both
     * pointing at that one payment. See App\Models\PaymentReference.
     *
     * `slots.*` is "Y-m-d H:i" — the slot's real calendar date + time —
     * instead of a bare time. This is the actual fix for "can't book
     * separate dates at once": previously only a bare time was sent, so
     * the same clock hour on two different real dates collided on the
     * `distinct` validation rule below even though they weren't actually
     * the same slot.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            // Kept as a sanity anchor (must not be in the past) and as a
            // fallback label — the slot dates below are what's actually
            // authoritative for what gets booked.
            'date' => ['required', 'date', 'after_or_equal:today'],
            'slots'          => ['required', 'array', 'min:1', 'max:60'],
            'slots.*'        => ['required', 'date_format:Y-m-d H:i', 'distinct'],
            'payment_method' => ['nullable', 'in:qrph'],
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
        ]);

        $guestEmail = $this->verifyGoogleIdToken($validated['google_id_token']);
        if ($guestEmail === null) {
            return response()->json([
                'message' => "We couldn't verify that Google sign-in. Please sign in again.",
            ], 422);
        }

        $court = Court::findOrFail($validated['court_id']);

        try {
            $groups = $this->resolveSlotGroups((int) $validated['court_id'], $validated['slots']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $stepMinutes    = BookingHours::stepMinutes();
        $slotPrice      = $court->hourly_rate * ($stepMinutes / 60);
        $totalSlotCount = collect($groups)->sum(fn ($windows) => count($windows));

        $expiresAt = now()->addMinutes(PaymentWindows::BOOKING_EXPIRY_MINUTES);

        try {
            $result = DB::transaction(function () use (
                $validated, $court, $groups, $slotPrice, $totalSlotCount,
                $guestEmail, $expiresAt
            ) {
                // Lock the court row for the rest of this transaction —
                // see the original single-date version of this method
                // for the full race-condition reasoning. Still needed
                // here for exactly the same reason, just covering every
                // date group instead of one.
                Court::where('id', $court->id)->lockForUpdate()->first();

                foreach ($groups as $dateStr => $windows) {
                    foreach ($windows as [$slotStart, $slotEnd]) {
                        $slotConflict = BookingSlot::whereHas('booking', function ($q) use ($court) {
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

                // Equipment stock re-checked inside the same lock, across
                // EVERY selected hour on EVERY selected date — an item
                // has to be free for all of them, not just one, so take
                // the minimum available across the whole multi-date
                // selection.
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

                $isPaid = false;

                // One shared payment for the whole checkout, however many
                // dates it covers.
                $paymentReference = PaymentReferenceModel::create([
                    'payment_reference' => null,
                    'payment_method'    => 'qrph',
                    'amount'            => $totalAmount,
                    'confirmed_at'      => $isPaid ? now() : null,
                ]);

                $bookings = [];
                $isFirstGroup = true;

                foreach ($groups as $dateStr => $windows) {
                    $envelopeStart = $windows[0][0];
                    $envelopeEnd   = $windows[count($windows) - 1][1];
                    $dateAmount    = round($slotPrice * count($windows), 2);

                    $booking = Booking::create([
                        'user_id'              => null,
                        'customer_name'        => $validated['guest_name'],
                        'contact_number'       => $validated['guest_contact'],
                        'email'                => $guestEmail,
                        'court_id'             => $court->id,
                        'payment_reference_id' => $paymentReference->id,
                        'payment_method'       => 'qrph',
                        'date'                 => $dateStr,
                        'start_time'           => $envelopeStart->format('H:i:s'),
                        'end_time'             => $envelopeEnd->format('H:i:s'),
                        // Equipment cost is billed entirely on the
                        // earliest date's booking (see below) rather than
                        // split across dates — keeps "amount" additions
                        // across all bookings under one payment equal to
                        // the total actually charged, without having to
                        // invent a per-date equipment split the guest
                        // never chose.
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

        // Logged after the transaction commits, not inside it — a
        // checkout that gets rolled back (slot conflict, stock conflict,
        // etc.) should never leave a log entry behind. One log line per
        // date booked.
        foreach ($bookings as $booking) {
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
                    'payment_method' => 'qrph',
                    'amount'         => $booking->amount,
                    'status'         => $booking->status,
                ],
            );

        }

        return response()->json([
            'message'    => 'Booking submitted! Generate your QR Ph code to complete payment.',
            'bookings'   => collect($bookings)->map(fn ($b) => [
                'booking_id' => $b->id,
                'date'       => $b->date->toDateString(),
                'poll_token' => $b->poll_token,
                'amount'     => $b->amount,
            ]),
            // Backwards-compatible top-level fields mirroring the FIRST
            // booking, for any older frontend code that hasn't been
            // updated yet to read the `bookings` array above.
            'booking_id' => $bookings[0]->id,
            'poll_token' => $bookings[0]->poll_token,
            'expires_at' => $bookings[0]->expires_at?->toIso8601String(),
            'amount'     => $result['paymentReference']->amount,
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
     * Full-page replacement for the old "waiting for GCash/Landbank
     * payment" modal. A modal could be dismissed by accident (the only
     * button in it both closed the modal AND cancelled the booking —
     * there was no way to "just close" it), and any JS-only countdown/
     * polling state was lost on refresh. This page has none of those
     * problems: it's seeded fresh from the database on every load/
     * refresh, closing the tab or hitting back does nothing destructive,
     * and the single "Cancel booking" button is unambiguous about what
     * it does. Same poll_token gate as status()/cancel() — no session
     * needed, since guests have none.
     */
    public function waiting(Request $request, Booking $booking)
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $booking->poll_token || ! hash_equals($booking->poll_token, $token)) {
            abort(403);
        }

        $booking->load('court');

        // A multi-date checkout shares one payment_reference across several
        // Booking rows (see PaymentReference's docblock) — pull every sibling
        // under the same payment so the waiting page shows everything the
        // guest is paying for, not just the one date this particular booking
        // row happens to be.
        $siblingBookings = Booking::where('payment_reference_id', $booking->payment_reference_id)
            ->orderBy('date')
            ->with('court')
            ->get();

        $paymentReference = PaymentReferenceModel::find($booking->payment_reference_id);

        return view('payment-waiting', [
            'booking'         => $booking,
            'siblingBookings' => $siblingBookings,
            'totalAmount'     => $paymentReference->amount ?? $siblingBookings->sum('amount'),
            'token'           => $token,
            'statusUrl'       => route('guest.book.status', ['booking' => $booking->id]),
            'cancelUrl'       => route('guest.book.cancel', ['booking' => $booking->id]),
            'cancelAllUrl'    => route('guest.book.cancel-all', ['booking' => $booking->id]),
            'referenceUrl'    => route('guest.book.update-reference', ['booking' => $booking->id]),
            'landingUrl'      => route('landing'),
        ]);
    }

    /**
     * Lets the guest give up on a pending GCash payment and release the
     * slot early, instead of waiting out the full confirmation window.
     * Only works while the booking is still pending — once GCash confirms
     * it (or it's already cancelled/expired) this is a no-op. Cancels
     * only THIS booking (this one date) — a sibling booking sharing the
     * same payment_reference from the same multi-date checkout is
     * unaffected, since the guest may still want the other date(s).
     */
    public function cancel(Request $request, Booking $booking)
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $booking->poll_token || ! hash_equals($booking->poll_token, $token)) {
            abort(403);
        }

        $pendingSiblingCount = Booking::where('payment_reference_id', $booking->payment_reference_id)
            ->where('status', 'pending')
            ->count();

        if ($pendingSiblingCount > 1) {
            return response()->json([
                'message' => 'This checkout includes multiple dates and must be cancelled all at once.',
            ], 422);
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

    /** Cancel every still-pending date attached to this one checkout. */
    public function cancelAll(Request $request, Booking $booking)
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! $booking->poll_token || ! hash_equals($booking->poll_token, $token)) {
            abort(403);
        }

        $cancelled = 0;
        DB::transaction(function () use ($booking, &$cancelled) {
            $siblings = Booking::where('payment_reference_id', $booking->payment_reference_id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($siblings as $sibling) {
                $sibling->update(['status' => 'cancelled']);
                $cancelled++;
            }
        });

        if ($cancelled > 0) {
            ActivityLogger::log(
                'booking.cancelled_all',
                sprintf('%s cancelled %d pending booking date(s) from one checkout.', $booking->customer_name, $cancelled),
                actor: null,
                subject: $booking,
            );
        }

        return response()->json(['status' => 'cancelled', 'cancelled' => $cancelled]);
    }

    /**
     * Lets the guest fix a typo'd reference number on their own still-
     * pending booking, instead of the only options being "wait for it to
     * expire" or "cancel and start the whole booking over" — the slot
     * and everything else about the booking stays exactly as-is, only
     * the reference number (and the retroactive payment match it
     * unlocks) changes.
     *
     * Updates the SHARED payment_reference row, not just this one
     * booking — if this booking came from a multi-date checkout, every
     * sibling booking under the same payment gets the corrected
     * reference too, and a match here confirms all of them at once
     * (they were always one payment, not several). A no-op once the
     * payment is already confirmed/cancelled — see the already_resolved
     * branch below.
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
            // confirming (or an expire command cancelling) this exact
            // booking between the check below and the update at the
            // bottom could get silently clobbered by this request, or
            // vice versa.
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($booking->status !== 'pending') {
                return ['status' => $booking->status, 'already_resolved' => true];
            }

            $paymentReference = PaymentReferenceModel::where('id', $booking->payment_reference_id)
                ->lockForUpdate()
                ->first();

            if ($paymentReference === null) {
                // Shouldn't happen — every booking created by store()
                // gets one in the same transaction — but fail safe
                // rather than fatal if it ever does.
                return ['status' => $booking->status, 'already_resolved' => true];
            }

            $paymentReference->update(['payment_reference' => $validated['payment_reference']]);

            // Same rule as store()'s retroactive claim and both webhook
            // controllers: reference number is the only thing that can
            // confirm a match. No "only one candidate at this amount"
            // fallback — see those for why.
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

                $booking->refresh();
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
            ! empty($result['already_resolved']) ? 422 : 200,
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
