<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Services\CourtConflictService;
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
    public function index(Request $request)
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
        $boardStart = Carbon::today()->subDay(); // includes overnight tails
        $boardEnd = Carbon::today()->addMonths(6);

        $courtBoardBookings = Booking::with(['court', 'slots', 'equipment.equipment'])
            ->whereBetween('date', [$boardStart, $boardEnd])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (Booking $booking) => [
                'id'       => $booking->id,
                'customer' => $booking->customer_name,
                'contact_number' => $booking->contact_number,
                'email' => $booking->email,
                'notes' => $booking->notes,
                'court_id' => $booking->court_id,
                'court'    => $booking->court?->name ?? ('Court ' . $booking->court_id),
                'date'     => $booking->date?->toDateString(),
                'start'    => substr((string) $booking->start_time, 0, 5),
                'end'      => substr((string) $booking->end_time, 0, 5),
                'status'   => $booking->status,
                'source'   => $booking->source,
                'amount'   => (float) $booking->amount,
                'slots'    => $booking->slots->map(fn ($slot) => [
                    'date'  => $slot->date?->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end'   => substr((string) $slot->end_time, 0, 5),
                ])->values(),
                'equipment' => $booking->equipment->map(fn ($line) => [
                    'name'     => $line->equipment?->name ?? 'Equipment',
                    'quantity' => $line->quantity,
                ])->values(),
            ])
            ->values();

        $courtBoardClosures = CourtClosure::whereBetween('date', [$boardStart, $boardEnd])
            ->get()
            ->map(fn (CourtClosure $closure) => [
                'court_id' => $closure->court_id,
                'date'     => $closure->date->toDateString(),
                'reason'   => $closure->reason,
            ])
            ->values();

        $courtBoardBlocks = CourtBlock::with('court')
            ->whereBetween('block_date', [$boardStart, $boardEnd])
            ->orderBy('block_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (CourtBlock $block) => [
                'id'         => $block->id,
                'court_id'   => $block->court?->id,
                'type'       => $block->type,
                'title'      => $block->title,
                'notes'      => $block->notes,
                'block_date' => $block->block_date?->toDateString(),
                'start_time' => substr((string) $block->start_time, 0, 5),
                'end_time'   => substr((string) $block->end_time, 0, 5),
            ])
            ->values();

        $courts = Court::orderBy('name')->get(['id', 'name', 'hourly_rate']);

        $businessSettings = BusinessSetting::current();

        return view('admin.bookings.index', compact(
            'bookings', 'courts', 'courtBoardBookings', 'courtBoardClosures', 'courtBoardBlocks', 'businessSettings'
        ));
    }

    public function update(Request $request, Booking $booking, CourtConflictService $conflicts): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'required', 'string', 'max:30', 'regex:/^[0-9+\\-\\s()]{7,30}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'court_id' => ['sometimes', 'required', 'integer', 'exists:courts,id'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'acknowledge_price_change' => ['sometimes', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($booking, $validated, $conflicts) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'expired'], true)) {
                return ['rejected' => 'Cancelled or expired bookings cannot be edited.'];
            }

            $courtId = (int) ($validated['court_id'] ?? $locked->court_id);
            $date = $validated['date'] ?? $locked->date->toDateString();
            $startTime = $validated['start_time'] ?? substr((string) $locked->start_time, 0, 5);
            $endTime = $validated['end_time'] ?? substr((string) $locked->end_time, 0, 5);
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$startTime}", config('app.timezone'));
            $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$endTime}", config('app.timezone'));
            if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();

            $settings = BusinessSetting::current();
            $step = BookingHours::stepMinutes();
            $duration = $start->diffInMinutes($end);
            if ($start->minute % $step !== 0 || $end->minute % $step !== 0
                || $duration < BookingHours::minDurationHours() * 60
                || $duration > BookingHours::maxDurationHours() * 60) {
                throw ValidationException::withMessages([
                    'end_time' => 'Choose a duration and time aligned with the configured booking step.',
                ]);
            }

            $oldCourtId = (int) $locked->court_id;
            $scheduleChanged = $courtId !== $oldCourtId
                || $date !== $locked->date->toDateString()
                || $startTime !== substr((string) $locked->start_time, 0, 5)
                || $endTime !== substr((string) $locked->end_time, 0, 5);

            $courtIds = array_values(array_unique(array_filter([$oldCourtId, $courtId], fn ($id) => $id > 0)));
            sort($courtIds);
            $courts = Court::query()->whereIn('id', $courtIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $court = $courts->get($courtId);
            if ($scheduleChanged && ! $court) return ['rejected' => 'The selected court is no longer available.'];

            if ($scheduleChanged) {
                $conflict = $conflicts->findConflict($courtId, $date, $startTime, $endTime, (int) $locked->id);
                if ($conflict !== null) return ['conflict' => $conflict];
            }

            $oldAmount = (float) $locked->amount;
            $oldSlotTotal = (float) $locked->slots()->where('is_active', true)->sum('price');
            $newSlotTotal = $oldSlotTotal;
            $slotPrices = [];
            if ($scheduleChanged) {
                for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addMinutes($step)) {
                    $slotPrices[] = round(
                        $settings->applyPeakAdjustment((float) $court->hourly_rate, $cursor->hour) * ($step / 60),
                        2
                    );
                }
                $newSlotTotal = round(array_sum($slotPrices), 2);
            }

            $newAmount = $scheduleChanged && $oldSlotTotal > 0
                ? round($oldAmount - $oldSlotTotal + $newSlotTotal, 2)
                : $oldAmount;
            if (array_key_exists('amount', $validated) && $validated['amount'] !== null) {
                $newAmount = round((float) $validated['amount'], 2);
            }
            $amountChanged = abs($newAmount - $oldAmount) >= 0.005;
            if ($amountChanged && in_array($locked->status, ['paid', 'confirmed'], true)
                && ! ($validated['acknowledge_price_change'] ?? false)) {
                return ['price_acknowledgement_required' => [
                    'message' => 'Acknowledge the price change before saving this paid or confirmed booking.',
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmount,
                ]];
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
            if ($amountChanged) $locked->amount = $newAmount;
            $locked->save();

            if ($scheduleChanged) {
                $locked->slots()->delete();
                foreach ($slotPrices as $index => $price) {
                    $slotStart = $start->addMinutes($index * $step);
                    $locked->slots()->create([
                        'court_id' => $courtId,
                        'date' => $slotStart->toDateString(),
                        'start_time' => $slotStart->format('H:i:s'),
                        'end_time' => $slotStart->addMinutes($step)->format('H:i:s'),
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
            ];
        });

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'The selected time conflicts with an existing booking, block, or closure.',
                'conflict' => $result['conflict'],
            ], 422);
        }
        if (isset($result['price_acknowledgement_required'])) {
            return response()->json($result['price_acknowledgement_required'], 422);
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
        ActivityLogger::log(
            'booking.admin_updated',
            sprintf('%s edited booking #%s.', auth()->user()->name, $updated->id),
            subject: $updated,
            properties: $properties,
        );

        return response()->json([
            'message' => 'Booking updated.',
            'booking' => [
                'id' => $updated->id,
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
                'slots' => $updated->slots->map(fn ($slot) => [
                    'date' => $slot->date->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end' => substr((string) $slot->end_time, 0, 5),
                ])->values(),
                'equipment' => $updated->equipment->map(fn ($line) => [
                    'name' => $line->equipment?->name ?? 'Equipment',
                    'quantity' => $line->quantity,
                ])->values(),
            ],
        ]);
    }
}
