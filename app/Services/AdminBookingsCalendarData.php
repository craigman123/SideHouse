<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Models\WeeklyCourtClosure;
use App\Models\BusinessSetting;
use App\Services\UserAccessService;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdminBookingsCalendarData
{
    public function forRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $canExposePriceRules = auth()->check()
            && app(UserAccessService::class)->allows(auth()->user(), 'bookings.create');
        $queryFrom = $from->copy()->subDay()->toDateString();
        $from = $from->toDateString();
        $to = $to->toDateString();

        $bookingRows = Booking::with(['court', 'slots', 'equipment.equipment'])
            ->whereDate('date', '>=', $queryFrom)->whereDate('date', '<=', $to)
            ->orderBy('date')->orderBy('start_time')->get();
        $bookings = $bookingRows
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'updated_at' => $booking->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                'customer' => $booking->customer_name,
                'contact_number' => $booking->contact_number,
                'email' => $booking->email,
                'notes' => $booking->notes,
                'court_id' => $booking->court_id,
                'court' => $booking->court?->name ?? ('Court '.$booking->court_id),
                'date' => $booking->date?->toDateString(),
                'start' => substr((string) $booking->start_time, 0, 5),
                'end' => substr((string) $booking->end_time, 0, 5),
                'status' => $booking->status,
                'source' => $booking->source,
                'qr_paid' => $booking->payment_method === 'qrph' || $booking->payment_intent_id !== null || $booking->payment_reference_id !== null,
                'amount' => (float) $booking->amount,
                'slots' => $booking->slots->where('is_active', true)->map(fn ($slot) => [
                    'date' => $slot->date?->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end' => substr((string) $slot->end_time, 0, 5),
                    ...($canExposePriceRules ? ['price' => (float) $slot->price] : []),
                ])->values(),
                'equipment' => $booking->equipment->map(fn ($line) => [
                    'name' => $line->equipment?->name ?? 'Equipment',
                    'quantity' => $line->quantity,
                    ...($canExposePriceRules ? ['price_each' => (float) $line->price_each] : []),
                ])->values(),
            ])->values();

        $closures = CourtClosure::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->map(fn (CourtClosure $closure) => [
            'court_id' => $closure->court_id,
            'date' => $closure->date->toDateString(),
            'reason' => $closure->reason,
        ])->values();

        $availability = app(OpenPlayAvailability::class);
        $blocks = CourtBlock::with('court')->whereDate('block_date', '>=', $queryFrom)->whereDate('block_date', '<=', $to)
            ->orderBy('block_date')->orderBy('start_time')->get()->map(function (CourtBlock $block) use ($availability, $bookingRows) {
                $participants = $block->type === 'open_play'
                    ? $availability->participants($block, $bookingRows)
                    : [];
                return [
                'id' => $block->id,
                'updated_at' => $block->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                'court_id' => $block->court?->id,
                'type' => $block->type,
                'title' => $block->title,
                'notes' => $block->notes,
                'block_date' => $block->block_date?->toDateString(),
                'start_time' => substr((string) $block->start_time, 0, 5),
                'end_time' => substr((string) $block->end_time, 0, 5),
                'max_players' => $block->max_players,
                'price_per_player' => $block->price_per_player,
                    'participant_count' => $block->type === 'open_play' ? count($participants) : null,
                    'participants' => $participants,
                    ];
            })->values();

        $weeklyRules = WeeklyCourtClosure::query()->where('active', true)->whereIn('weekday', [0, 1, 2, 3, 4, 5, 6])->get();
        $weeklyBlocks = collect();
        for ($date = CarbonImmutable::parse($queryFrom, 'Asia/Manila'); $date->toDateString() <= $to; $date = $date->addDay()) {
            foreach ($weeklyRules->where('weekday', $date->dayOfWeek) as $rule) {
                $weeklyBlocks->push([
                    'id' => 'weekly-'.$rule->id.'-'.$date->toDateString(),
                    'source' => 'weekly_closure',
                    'updated_at' => $rule->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                    'court_id' => $rule->court_id,
                    'type' => 'closed',
                    'title' => 'Weekly closure',
                    'notes' => $rule->reason,
                    'block_date' => $date->toDateString(),
                    'start_time' => substr((string) $rule->start_time, 0, 5),
                    'end_time' => substr((string) $rule->end_time, 0, 5),
                    'max_players' => null,
                    'price_per_player' => null,
                    'participant_count' => null,
                ]);
            }
        }
        $blocks = $blocks->concat($weeklyBlocks)->sortBy([['block_date', 'asc'], ['start_time', 'asc']])->values();

        $cutoffs = SpecificDateTimeClosure::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->map(fn ($cutoff) => [
            'date' => $cutoff->date->toDateString(),
            'time_closing' => substr((string) $cutoff->time_closing, 0, 5),
        ])->values();

        return [
            'bookings' => $bookings,
            'closures' => $closures,
            'blocks' => $blocks,
            'closingCutoffs' => $cutoffs,
            'courts' => Court::orderBy('name')->get(['id', 'name', 'hourly_rate']),
            'businessSettings' => $this->businessSettings(),
            'pricingRules' => $canExposePriceRules ? app(BookingPricingService::class)->clientRules() : [],
        ];
    }

    private function businessSettings(): array
    {
        $settings = BusinessSetting::current();
        return [
            'today' => CarbonImmutable::now('Asia/Manila')->toDateString(),
            'server_time' => CarbonImmutable::now('Asia/Manila')->toIso8601String(),
            'open_hour' => $settings->open_hour,
            'close_hour' => $settings->close_hour,
            'closed_weekdays' => $settings->closed_weekdays,
            'step_minutes' => $settings->step_minutes,
            'peak_start_hour' => $settings->peak_start_hour,
            'peak_end_hour' => $settings->peak_end_hour,
            'peak_adjustment_type' => $settings->peak_adjustment_type,
            'peak_adjustment_value' => $settings->peak_adjustment_value,
        ];
    }

    public function versionForRange(CarbonInterface $from, CarbonInterface $to): string
    {
        $from = $from->copy()->subDay()->toDateString();
        $to = $to->toDateString();
        $parts = [];
        foreach ([
            ['bookings', 'date'], ['court_blocks', 'block_date'],
            ['court_closures', 'date'], ['specific_date_time_closed', 'date'],
            ['weekly_court_closures', null],
            ['courts', null], ['business_settings', null],
        ] as [$table, $column]) {
            $query = DB::table($table);
            if ($column !== null) $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
            $aggregate = $query->selectRaw('COUNT(*) as row_count, MAX(updated_at) as latest')->first();
            $parts[] = [$table, (int) $aggregate->row_count, $aggregate->latest];
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
