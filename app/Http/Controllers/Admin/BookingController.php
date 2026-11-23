<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Services\AdminCalendarOverrideWarnings;
use App\Services\AdminBookingsCalendarData;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request, AdminBookingsCalendarData $calendarData)
    {
        $bookings = Booking::query()
            ->status($request->status)
            ->search($request->search)
            ->onDate($request->date)
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate(15)
            ->withQueryString();

        // The table above remains paginated and filterable. The court board
        // needs a small, date-windowed dataset instead so its day switcher
        // can update instantly without replacing the page.
        $boardStart = Carbon::today('Asia/Manila')->subDay(); // includes overnight tails
        $boardEnd = Carbon::today('Asia/Manila')->addMonths(6);

        $boardData = $calendarData->forRange($boardStart, $boardEnd);
        $courtBoardBookings = $boardData['bookings'];
        $courtBoardClosures = $boardData['closures'];
        $courtBoardBlocks = $boardData['blocks'];
        $courtBoardClosingCutoffs = $boardData['closingCutoffs'];
        $courts = $boardData['courts'];
        $pricingRules = $boardData['pricingRules'];

        $businessSettings = (object) $boardData['businessSettings'];

        return view('admin.bookings.index', compact(
            'bookings', 'courts', 'courtBoardBookings', 'courtBoardClosures', 'courtBoardBlocks', 'courtBoardClosingCutoffs', 'businessSettings', 'pricingRules'
        ));
    }

    public function calendar(Request $request, AdminBookingsCalendarData $calendarData, UserAccessService $access): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to']);
        if ($from->diffInDays($to) >= 100) {
            throw ValidationException::withMessages(['to' => 'The requested calendar range cannot exceed 100 days.']);
        }

        $version = $calendarData->versionForRange($from, $to);
        $etag = '"'.$version.'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304)
                ->header('ETag', $etag)
                ->header('X-Venue-Date', CarbonImmutable::now('Asia/Manila')->toDateString())
                ->header('X-Venue-Time', CarbonImmutable::now('Asia/Manila')->toIso8601String());
        }

        $rangeData = $calendarData->forRange($from, $to);
        if (! $access->allows($request->user(), 'bookings.view')) {
            $rangeData['bookings'] = [];
        }

        return response()->json([
            ...$rangeData,
            'server_time' => now('Asia/Manila')->toIso8601String(),
        ])->header('ETag', $etag)
            ->header('X-Venue-Date', CarbonImmutable::now('Asia/Manila')->toDateString())
            ->header('X-Venue-Time', CarbonImmutable::now('Asia/Manila')->toIso8601String());
    }

    public function update(Request $request, Booking $booking, AdminCalendarOverrideWarnings $overrideWarnings): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'required', 'string', 'max:30', 'regex:/^[0-9+\\-\\s()]{7,30}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'court_id' => ['sometimes', 'required', 'integer', 'exists:courts,id'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'price_decision' => ['sometimes', 'required', 'in:keep,new'],
            'expected_amount' => ['required_if:price_decision,new', 'numeric', 'decimal:0,2', 'min:0'],
            'price_rules_version' => ['required_if:price_decision,new', 'string', 'size:64'],
            'acknowledge_price_change' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
            'adjust_minutes' => ['sometimes', 'integer'],
            'expected_updated_at' => ['sometimes', 'required', 'date'],
        ]);

        if (($validated['price_decision'] ?? null) === 'new'
            && ! app(UserAccessService::class)->allows($request->user(), 'bookings.create')) {
            return response()->json(['message' => 'You do not have permission to change booking prices.'], 403);
        }

        $result = DB::transaction(function () use ($booking, $validated, $overrideWarnings, $request) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (isset($validated['expected_updated_at'])
                && \Carbon\Carbon::parse($validated['expected_updated_at'])->format('U.u') !== $locked->updated_at->format('U.u')) {
                return ['stale' => $locked->load(['court', 'slots', 'equipment.equipment'])];
            }
            if (in_array($locked->status, ['cancelled', 'expired'], true)) {
                return ['rejected' => 'Cancelled or expired bookings cannot be edited.'];
            }

            $courtId = (int) ($validated['court_id'] ?? $locked->court_id);
            $date = $validated['date'] ?? $locked->date->toDateString();
            $adjustMinutes = (int) ($validated['adjust_minutes'] ?? 0);
            $startTime = array_key_exists('adjust_minutes', $validated)
                ? substr((string) $locked->start_time, 0, 5)
                : ($validated['start_time'] ?? substr((string) $locked->start_time, 0, 5));
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$startTime}", config('app.timezone'));
            $oldEnd = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$locked->date->toDateString()} ".substr((string) $locked->end_time, 0, 5), config('app.timezone'));
            if ($oldEnd->lessThanOrEqualTo(CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$locked->date->toDateString()} ".substr((string) $locked->start_time, 0, 5), config('app.timezone')))) $oldEnd = $oldEnd->addDay();
            if (array_key_exists('adjust_minutes', $validated)) {
                $end = $oldEnd->addMinutes($adjustMinutes);
                if ($date !== $locked->date->toDateString()) {
                    $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$startTime}", config('app.timezone'));
                    $end = $start->addMinutes($oldEnd->diffInMinutes(CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$locked->date->toDateString()} ".substr((string) $locked->start_time, 0, 5), config('app.timezone'))))
                        ->addMinutes($adjustMinutes);
                }
            } else {
                $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} ".($validated['end_time'] ?? substr((string) $locked->end_time, 0, 5)), config('app.timezone'));
                if ($end->equalTo($start)) {
                    throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
                }
                if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();
            }
            $endTime = $end->format('H:i');

            $step = BookingHours::stepMinutes();
            $duration = $start->diffInMinutes($end);
            if ($duration < $step) {
                throw ValidationException::withMessages([
                    'end_time' => "Choose a duration of at least {$step} minutes.",
                ]);
            }

            $oldCourtId = (int) $locked->court_id;
            $scheduleChanged = $courtId !== $oldCourtId
                || $date !== $locked->date->toDateString()
                || $startTime !== substr((string) $locked->start_time, 0, 5)
                || $endTime !== substr((string) $locked->end_time, 0, 5);
            $venueNow = CarbonImmutable::now('Asia/Manila');
            $oldStart = substr((string) $locked->start_time, 0, 5);
            $oldEndTime = substr((string) $locked->end_time, 0, 5);
            $oldIsOvernight = $oldEndTime <= $oldStart;
            $oldIsPast = $locked->date->toDateString() < $venueNow->toDateString()
                || ($locked->date->toDateString() === $venueNow->toDateString()
                    && ! $oldIsOvernight && $oldEndTime <= $venueNow->format('H:i'));
            if ($oldIsPast && ($scheduleChanged || (array_key_exists('amount', $validated)
                && $validated['amount'] !== null && abs((float) $validated['amount'] - (float) $locked->amount) >= 0.005))) {
                return ['rejected' => 'Past bookings cannot have their date, time, court, or amount changed.'];
            }

            $courtIds = array_values(array_unique(array_filter([$oldCourtId, $courtId], fn ($id) => $id > 0)));
            sort($courtIds);
            $courts = Court::query()->whereIn('id', $courtIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $court = $courts->get($courtId);
            if ($scheduleChanged && ! $court) return ['rejected' => 'The selected court is no longer available.'];

            if ($scheduleChanged && app(\App\Services\CourtBlockClosureAvailability::class)->firstClosedOverlap($courtId, $start, $end)) {
                return ['rejected' => 'This court is closed during the selected time.'];
            }

            $warnings = $scheduleChanged
                ? $overrideWarnings->forInterval($courtId, $date, $startTime, $endTime, (int) $locked->id)
                : [];
            $overlapIds = collect($warnings)->where('code', 'overlaps_booking')->flatMap(fn ($warning) => $warning['item_ids'])->unique()->values()->all();

            $oldAmount = (float) $locked->amount;
            $isQrPaid = $locked->payment_method === 'qrph' || $locked->payment_intent_id !== null || $locked->payment_reference_id !== null;
            $mayChangeAmount = app(UserAccessService::class)->allows($request->user(), 'bookings.create');
            $priceDecision = $validated['price_decision'] ?? ($scheduleChanged && $mayChangeAmount && ! $isQrPaid ? 'new' : 'keep');
            if ($priceDecision === 'new' && ! $scheduleChanged) {
                return ['rejected' => 'A new calculated amount can only be applied while changing the booking schedule.'];
            }
            if (($validated['price_decision'] ?? null) === 'new' && ! ($validated['acknowledge_price_change'] ?? false)) {
                return ['rejected' => 'Acknowledge the calculated price change before confirming.'];
            }
            if (array_key_exists('amount', $validated) && $validated['amount'] !== null
                && abs((float) $validated['amount'] - $oldAmount) >= 0.005) {
                return ['rejected' => 'Booking amounts must use the calculated price confirmation.'];
            }
            if ($isQrPaid && (($priceDecision === 'new' && $scheduleChanged)
                || (array_key_exists('amount', $validated) && $validated['amount'] !== null
                    && abs((float) $validated['amount'] - $oldAmount) >= 0.005))) {
                return ['forbidden' => 'QR-paid booking amounts cannot be changed.'];
            }
            $oldSlotTotal = (float) $locked->slots()->where('is_active', true)->sum('price');
            $newSlotTotal = $oldSlotTotal;
            $slotPrices = [];
            $adjustOnly = array_key_exists('adjust_minutes', $validated)
                && $courtId === $oldCourtId && $date === $locked->date->toDateString();
            if ($scheduleChanged && ! $adjustOnly) {
                $pricedInterval = app(\App\Services\BookingPricingService::class)->interval($courtId, $start, $end);
                $slotPrices = $pricedInterval['slot_prices'];
                $newSlotTotal = $pricedInterval['total'];
            }

            if ($adjustOnly && $oldSlotTotal > 0) {
                if ($adjustMinutes > 0) {
                    $pricedInterval = app(\App\Services\BookingPricingService::class)->interval($courtId, $oldEnd, $end);
                    $slotPrices = $pricedInterval['slot_prices'];
                    $newSlotTotal = round($oldSlotTotal + $pricedInterval['total'], 2);
                } elseif ($adjustMinutes < 0) {
                    $removedTotal = (float) $locked->slots()->where('is_active', true)->where('start_time', '>=', $end->format('H:i:s'))->sum('price');
                    $newSlotTotal = round($oldSlotTotal - $removedTotal, 2);
                }
            }
            $calculatedAmount = $scheduleChanged && $oldSlotTotal > 0
                ? \App\Services\BookingPricingService::movedAmount($oldAmount, $oldSlotTotal, $newSlotTotal)
                : $oldAmount;
            $newAmount = $scheduleChanged
                ? ($priceDecision === 'new' ? $calculatedAmount : $oldAmount)
                : $calculatedAmount;
            $amountChanged = abs($newAmount - $oldAmount) >= 0.005;
            if ($amountChanged && in_array($locked->status, ['paid', 'confirmed'], true) && $newAmount < $oldAmount) {
                $warnings[] = ['code' => 'amount_below_paid', 'message' => 'Amount is lower than the amount previously paid', 'item_ids' => []];
            }
            if (! ($validated['dry_run'] ?? false) && $amountChanged && in_array($locked->status, ['paid', 'confirmed'], true)
                && ! ($validated['acknowledge_price_change'] ?? false)) {
                return ['price_ack_required' => ['old_amount' => $oldAmount, 'new_amount' => $newAmount]];
            }
            if ($scheduleChanged) $warnings = [...$warnings, ...$this->equipmentStockWarnings($locked, $start, $end, $step)];
            if ($validated['dry_run'] ?? false) {
                $pricingRules = app(\App\Services\BookingPricingService::class)->clientRules();
                return ['quote' => [
                    'old_amount' => $oldAmount, 'new_amount' => $calculatedAmount,
                    'amount_changed' => abs($calculatedAmount - $oldAmount) >= 0.005,
                    'old_date' => $locked->date->toDateString(),
                    'old_start' => substr((string) $locked->start_time, 0, 5),
                    'old_end' => substr((string) $locked->end_time, 0, 5),
                    'date' => $date,
                    'start' => $start->format('H:i'), 'end' => $end->format('H:i'),
                    'court_id' => $courtId,
                    'rules_version' => $pricingRules['version'],
                    'qr_paid' => $isQrPaid,
                    'can_change_amount' => app(UserAccessService::class)->allows($request->user(), 'bookings.create') && ! $isQrPaid,
                    'warnings' => $warnings,
                ]];
            }

            if ($scheduleChanged && ($validated['price_decision'] ?? null) === 'new') {
                $pricingRules = app(\App\Services\BookingPricingService::class)->clientRules();
                if (abs((float) $validated['expected_amount'] - $calculatedAmount) >= 0.005
                    || ! hash_equals($pricingRules['version'], (string) $validated['price_rules_version'])) {
                    return ['price_stale' => [
                        'old_amount' => $oldAmount, 'new_amount' => $calculatedAmount,
                        'amount_changed' => abs($calculatedAmount - $oldAmount) >= 0.005,
                        'date' => $date, 'start' => $start->format('H:i'), 'end' => $end->format('H:i'),
                        'rules_version' => $pricingRules['version'],
                        'can_change_amount' => app(UserAccessService::class)->allows($request->user(), 'bookings.create') && ! $isQrPaid,
                        'qr_paid' => $isQrPaid,
                    ]];
                }
            }

            foreach (['customer_name', 'contact_number', 'email', 'notes'] as $field) {
                if (array_key_exists($field, $validated)) $locked->{$field} = $validated[$field];
            }
            if ($scheduleChanged) {
                $locked->court_id = $courtId;
                $locked->date = $date;
                $locked->start_time = $start->format('H:i:s');
                $locked->end_time = $end->format('H:i:s');
            }
            if ($scheduleChanged && $adjustOnly) {
                if ($adjustMinutes > 0) {
                    foreach ($slotPrices as $index => $price) {
                        $slotStart = $oldEnd->addMinutes($index * $step);
                        $locked->slots()->create([
                            'court_id' => $courtId, 'date' => $slotStart->toDateString(),
                            'start_time' => $slotStart->format('H:i:s'),
                        'end_time' => $slotStart->addMinutes($step)->format('H:i:s'), 'price' => $price,
                        ]);
                    }
                } elseif ($adjustMinutes < 0) {
                    $locked->slots()->where('is_active', true)->where(function ($query) use ($end) {
                        $query->whereDate('date', '>', $end->toDateString())
                            ->orWhere(function ($dateQuery) use ($end) {
                                $dateQuery->whereDate('date', $end->toDateString())->where('start_time', '>=', $end->format('H:i:s'));
                            });
                    })->update(['is_active' => false]);
                }
            }
            if ($amountChanged) $locked->amount = $newAmount;
            $locked->save();

            if ($scheduleChanged && ! $adjustOnly) {
                $locked->slots()->delete();
                foreach ($slotPrices as $index => $price) {
                    $slotStart = $start->addMinutes($index * $step);
                    $slotEnd = $slotStart->addMinutes($step);
                    if ($slotEnd->greaterThan($end)) $slotEnd = $end;
                    $locked->slots()->create([
                        'court_id' => $courtId,
                        'date' => $slotStart->toDateString(),
                        'start_time' => $slotStart->format('H:i:s'),
                        'end_time' => $slotEnd->format('H:i:s'),
                        'price' => $price,
                    ]);
                }
            }

            return [
                'booking' => $locked->load(['court', 'slots', 'equipment.equipment']),
                'schedule_changed' => $scheduleChanged,
                'amount_changed' => $amountChanged,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'adjust_minutes' => $adjustMinutes,
                'overlapping_booking_ids' => $overlapIds,
                'warnings' => $warnings,
            ];
        });

        if (isset($result['quote'])) {
            return response()->json(['quote' => $result['quote']]);
        }

        if (isset($result['forbidden'])) return response()->json(['message' => $result['forbidden']], 403);
        if (isset($result['price_ack_required'])) return response()->json([
            'message' => 'Acknowledge the price change before saving.', ...$result['price_ack_required'],
        ], 422);
        if (isset($result['price_stale'])) return response()->json([
            'message' => 'Prices changed while you were confirming. Review the updated amount.',
            'quote' => $result['price_stale'],
        ], 409);

        if (isset($result['stale'])) {
            $stale = $result['stale'];
            return response()->json([
                'message' => 'This booking changed while you were moving it. The latest version has been loaded.',
                'booking' => [
                    'id' => $stale->id, 'updated_at' => $stale->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                    'customer' => $stale->customer_name, 'contact_number' => $stale->contact_number,
                    'email' => $stale->email, 'notes' => $stale->notes, 'court_id' => $stale->court_id,
                    'court' => $stale->court?->name, 'date' => $stale->date->toDateString(),
                    'start' => substr((string) $stale->start_time, 0, 5), 'end' => substr((string) $stale->end_time, 0, 5),
                    'status' => $stale->status, 'source' => $stale->source, 'amount' => (float) $stale->amount,
                    'qr_paid' => $stale->payment_method === 'qrph' || $stale->payment_intent_id !== null || $stale->payment_reference_id !== null,
                    'slots' => $stale->slots->map(fn ($slot) => [
                        'date' => $slot->date->toDateString(), 'start' => substr((string) $slot->start_time, 0, 5),
                        'end' => substr((string) $slot->end_time, 0, 5),
                    ])->values(),
                    'equipment' => $stale->equipment->map(fn ($line) => [
                        'name' => $line->equipment?->name ?? 'Equipment', 'quantity' => $line->quantity,
                    ])->values(),
                ],
            ], 409);
        }

        if (isset($result['rejected'])) return response()->json(['message' => $result['rejected']], 422);

        /** @var Booking $updated */
        $updated = $result['booking'];
        $changes = array_intersect_key($updated->getChanges(), array_flip([
            'customer_name', 'contact_number', 'email', 'notes', 'court_id', 'date', 'start_time', 'end_time', 'amount',
        ]));
        $properties = ['changes' => $changes];
        if ($result['amount_changed']) {
            $properties['old_amount'] = $result['old_amount'];
            $properties['new_amount'] = $result['new_amount'];
        }
        if (($result['adjust_minutes'] ?? 0) > 0 && ($result['overlapping_booking_ids'] ?? []) !== []) {
            ActivityLogger::log(
                'booking.admin_extension_overlap',
                sprintf('%s extended booking #%s into an existing booking interval.', auth()->user()->name, $updated->id),
                subject: $updated,
                properties: ['overlapping_booking_ids' => $result['overlapping_booking_ids'], 'adjust_minutes' => $result['adjust_minutes']],
            );
        }
        if ($result['amount_changed'] && $result['new_amount'] < $result['old_amount']
            && in_array($updated->status, ['paid', 'confirmed'], true)) {
            ActivityLogger::log(
                'booking.admin_refund_review',
                sprintf('%s reduced the amount on paid booking #%s; review any refund.', auth()->user()->name, $updated->id),
                subject: $updated,
                properties: ['old_amount' => $result['old_amount'], 'new_amount' => $result['new_amount']],
            );
        }
        if ($result['schedule_changed'] || $result['amount_changed']) {
            ActivityLogger::log(
                'calendar.admin_override',
                sprintf('%s applied an admin calendar override to booking #%s.', auth()->user()->name, $updated->id),
                subject: $updated,
                properties: [
                    'admin_override' => true,
                    'booking_id' => $updated->id,
                    'warnings' => $result['warnings'],
                    'old_time' => ['date' => $booking->date->toDateString(), 'start' => substr((string) $booking->start_time, 0, 5), 'end' => substr((string) $booking->end_time, 0, 5)],
                    'new_time' => ['date' => $updated->date->toDateString(), 'start' => substr((string) $updated->start_time, 0, 5), 'end' => substr((string) $updated->end_time, 0, 5)],
                    'old_amount' => $result['old_amount'], 'new_amount' => $result['new_amount'],
                    'overlapped_item_ids' => $result['overlapping_booking_ids'],
                ],
            );
        }
        ActivityLogger::log(
            'booking.admin_updated',
            sprintf('%s edited booking #%s.', auth()->user()->name, $updated->id),
            subject: $updated,
            properties: $properties,
        );

        return response()->json([
            'message' => 'Booking updated.',
            'admin_override' => true,
            'warnings' => $result['warnings'],
            'booking' => [
                'id' => $updated->id,
                'updated_at' => $updated->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                'customer' => $updated->customer_name,
                'contact_number' => $updated->contact_number,
                'email' => $updated->email,
                'notes' => $updated->notes,
                'court_id' => $updated->court_id,
                'court' => $updated->court?->name,
                'date' => $updated->date->toDateString(),
                'start' => substr((string) $updated->start_time, 0, 5),
                'end' => substr((string) $updated->end_time, 0, 5),
                'status' => $updated->status,
                'source' => $updated->source,
                'amount' => (float) $updated->amount,
                'qr_paid' => $updated->payment_method === 'qrph' || $updated->payment_intent_id !== null || $updated->payment_reference_id !== null,
                'slots' => $updated->slots->map(fn ($slot) => [
                    'date' => $slot->date->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end' => substr((string) $slot->end_time, 0, 5),
                ])->values(),
                'equipment' => $updated->equipment->map(fn ($line) => [
                    'name' => $line->equipment?->name ?? 'Equipment',
                    'quantity' => $line->quantity,
                ])->values(),
                'admin_override' => true,
                'warnings' => $result['warnings'],
            ],
        ]);
    }

    private function equipmentStockWarnings(Booking $booking, CarbonImmutable $start, CarbonImmutable $end, int $step): array
    {
        $booking->loadMissing(['equipment.equipment', 'slots']);
        $warnings = [];
        foreach ($booking->equipment as $rental) {
            $equipment = $rental->equipment;
            if (! $equipment) continue;
            $unavailable = false;
            for ($cursor = $start; $cursor->lessThan($end);) {
                $slotEnd = $cursor->addMinutes($step);
                if ($slotEnd->greaterThan($end)) $slotEnd = $end;
                $available = $equipment->availableStock(
                    $cursor->toDateString(), $cursor->format('H:i:s'), $slotEnd->format('H:i:s')
                );
                $selfReservedHere = $booking->slots->contains(function ($slot) use ($cursor, $slotEnd) {
                    $date = $slot->date->toDateString();
                    if ($date !== $cursor->toDateString()) return false;
                    $ownStart = CarbonImmutable::parse($date.' '.$slot->start_time, config('app.timezone'));
                    $ownEnd = CarbonImmutable::parse($date.' '.$slot->end_time, config('app.timezone'));
                    return $ownStart->lessThan($slotEnd) && $ownEnd->greaterThan($cursor);
                });
                if ($available + ($selfReservedHere ? (int) $rental->quantity : 0) < (int) $rental->quantity) {
                    $unavailable = true;
                    break;
                }
                $cursor = $slotEnd;
            }
            if ($unavailable) {
                $warnings[] = [
                    'code' => 'equipment_stock',
                    'message' => "Equipment stock may be unavailable: {$equipment->name}",
                    'item_ids' => [(int) $equipment->id],
                ];
            }
        }
        return $warnings;
    }
}
