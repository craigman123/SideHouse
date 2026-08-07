<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Equipment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'payment_method' => ['required', 'in:arrival,ewallet'],
            'guest_name'     => ['required', 'string', 'max:255'],
            // Loose on purpose (7–30 digits/spaces/dashes/parens/+) since
            // this needs to accept PH mobile numbers in several common
            // formats without being a full phone-format validator.
            'guest_contact'  => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
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
        ]);

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

        $booking = DB::transaction(function () use ($validated, $court, $start, $end, $amount, $resolvedEquipment) {
            $booking = Booking::create([
                'user_id'        => null,
                'customer_name'  => $validated['guest_name'],
                'contact_number' => $validated['guest_contact'],
                'court_id'       => $court->id,
                'date'           => $validated['date'],
                'start_time'     => $start->format('H:i:s'),
                'end_time'       => $end->format('H:i:s'),
                'amount'         => $amount,
                'payment_method' => $validated['payment_method'],
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
            'message' => 'Booking submitted! We\'ll see you on the court.',
            'booking' => $booking,
        ]);
    }
}