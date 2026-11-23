<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Models\BusinessSetting;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdminBookingsCalendarData
{
    public function forRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $queryFrom = $from->copy()->subDay()->toDateString();
        $from = $from->toDateString();
        $to = $to->toDateString();

        $bookings = Booking::with(['court', 'slots', 'equipment.equipment'])
            ->whereDate('date', '>=', $queryFrom)->whereDate('date', '<=', $to)
            ->orderBy('date')->orderBy('start_time')->get()
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
                'amount' => (float) $booking->amount,
                'slots' => $booking->slots->map(fn ($slot) => [
                    'date' => $slot->date?->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end' => substr((string) $slot->end_time, 0, 5),
                ])->values(),
                'equipment' => $booking->equipment->map(fn ($line) => [
                    'name' => $line->equipment?->name ?? 'Equipment',
                    'quantity' => $line->quantity,
                ])->values(),
            ])->values();

        $closures = CourtClosure::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->map(fn (CourtClosure $closure) => [
            'court_id' => $closure->court_id,
            'date' => $closure->date->toDateString(),
            'reason' => $closure->reason,
        ])->values();

        $blocks = CourtBlock::with('court')->whereDate('block_date', '>=', $queryFrom)->whereDate('block_date', '<=', $to)
            ->orderBy('block_date')->orderBy('start_time')->get()->map(fn (CourtBlock $block) => [
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
                'participant_count' => $block->type === 'open_play'
                    ? app(OpenPlayAvailability::class)->joined($block, (int) $block->court_id) : null,
            ])->values();

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
