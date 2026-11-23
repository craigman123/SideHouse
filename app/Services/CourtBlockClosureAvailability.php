<?php

namespace App\Services;

use App\Models\CourtBlock;
use App\Models\WeeklyCourtClosure;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

class CourtBlockClosureAvailability
{
    public function closedRangesForDate(int $courtId, string $date): array
    {
        $dayStart = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
        $dayEnd = $dayStart->addDay();

        $specific = $this->closedBlocksForDate($courtId, $date)->map(function (CourtBlock $block) use ($dayStart, $dayEnd) {
            [$start, $end] = $this->blockInterval($block);
            if (! ($start->lessThan($dayEnd) && $end->greaterThan($dayStart))) return null;

            $rangeStart = $start->greaterThan($dayStart) ? $start : $dayStart;
            $rangeEnd = $end->lessThan($dayEnd) ? $end : $dayEnd;

            return [
                'id' => (int) $block->id,
                'start' => $rangeStart->format('H:i'),
                'end' => $rangeEnd->format('H:i'),
                'reason' => $block->notes ?: ($block->title ?: 'Court closed'),
            ];
        })->filter()->values()->all();

        $weekly = $this->weeklyRulesForDate($courtId, $date)->map(function (WeeklyCourtClosure $rule) use ($dayStart, $dayEnd, $date) {
            $anchorDate = $rule->weekday === (int) CarbonImmutable::parse($date, 'Asia/Manila')->dayOfWeek
                ? $date
                : CarbonImmutable::parse($date, 'Asia/Manila')->subDay()->toDateString();
            [$start, $end] = $this->weeklyInterval($rule, $anchorDate);
            if (! ($start->lessThan($dayEnd) && $end->greaterThan($dayStart))) return null;
            $rangeStart = $start->greaterThan($dayStart) ? $start : $dayStart;
            $rangeEnd = $end->lessThan($dayEnd) ? $end : $dayEnd;
            return [
                'id' => 'weekly-'.$rule->id.'-'.$anchorDate,
                'start' => $rangeStart->format('H:i'),
                'end' => $rangeEnd->format('H:i'),
                'reason' => 'Weekly closure'.($rule->reason ? ': '.$rule->reason : ''),
            ];
        })->filter()->values();

        return $specific->concat($weekly)->all();
    }

    public function firstClosedOverlap(int $courtId, DateTimeInterface $start, DateTimeInterface $end, ?int $ignoreBlockId = null): ?object
    {
        $start = CarbonImmutable::instance($start)->setTimezone('Asia/Manila');
        $end = CarbonImmutable::instance($end)->setTimezone('Asia/Manila');
        $blocks = CourtBlock::query()
            ->where('type', 'closed')
            ->where(fn ($query) => $query->whereNull('court_id')->orWhere('court_id', $courtId))
            ->when($ignoreBlockId !== null, fn ($query) => $query->where('id', '!=', $ignoreBlockId))
            ->whereDate('block_date', '>=', $start->startOfDay()->subDay()->toDateString())
            ->whereDate('block_date', '<=', $end->startOfDay()->toDateString())
            ->orderBy('block_date')->orderBy('start_time')->get();

        foreach ($blocks as $block) {
            [$blockStart, $blockEnd] = $this->blockInterval($block);
            if ($start->lessThan($blockEnd) && $end->greaterThan($blockStart)) return $block;
        }

        for ($day = $start->startOfDay()->subDay(); $day->lessThanOrEqualTo($end->startOfDay()); $day = $day->addDay()) {
            $weekday = $day->dayOfWeek;
            $rules = WeeklyCourtClosure::query()->where('active', true)->where('weekday', $weekday)
                ->where(fn ($query) => $query->whereNull('court_id')->orWhere('court_id', $courtId))
                ->when($ignoreBlockId !== null, fn ($query) => $query->where('id', '!=', $ignoreBlockId))
                ->get();
            foreach ($rules as $rule) {
                [$ruleStart, $ruleEnd] = $this->weeklyInterval($rule, $day->toDateString());
                if ($start->lessThan($ruleEnd) && $end->greaterThan($ruleStart)) return $rule;
            }
        }

        return null;
    }

    public function closedBlocksForDate(int $courtId, string $date): Collection
    {
        $previousDate = CarbonImmutable::parse($date, 'Asia/Manila')->subDay()->toDateString();

        return CourtBlock::query()
            ->where('type', 'closed')
            ->where(fn ($query) => $query->whereNull('court_id')->orWhere('court_id', $courtId))
            ->whereDate('block_date', '>=', $previousDate)
            ->whereDate('block_date', '<=', $date)
            ->orderBy('block_date')->orderBy('start_time')->get();
    }

    public function weeklyRulesForDate(int $courtId, string $date): Collection
    {
        $current = CarbonImmutable::parse($date, 'Asia/Manila');
        $previous = $current->subDay();
        $rules = WeeklyCourtClosure::query()->with(['court:id,name', 'creator:user_id,name'])
            ->where('active', true)
            ->whereIn('weekday', [$current->dayOfWeek, $previous->dayOfWeek])
            ->where(fn ($query) => $query->whereNull('court_id')->orWhere('court_id', $courtId))
            ->get();

        return $rules->filter(function (WeeklyCourtClosure $rule) use ($current, $previous) {
            if ($rule->weekday === $current->dayOfWeek) return true;
            return substr((string) $rule->end_time, 0, 5) <= substr((string) $rule->start_time, 0, 5);
        })->values();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function weeklyInterval(WeeklyCourtClosure $rule, string $date): array
    {
        $start = CarbonImmutable::parse($date.' '.$rule->start_time, 'Asia/Manila');
        $end = CarbonImmutable::parse($date.' '.$rule->end_time, 'Asia/Manila');
        if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();
        return [$start, $end];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function blockInterval(CourtBlock $block): array
    {
        $date = $block->block_date?->toDateString() ?? (string) $block->getRawOriginal('block_date');
        $start = CarbonImmutable::parse($date.' '.$block->start_time, 'Asia/Manila');
        $end = CarbonImmutable::parse($date.' '.$block->end_time, 'Asia/Manila');
        if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();

        return [$start, $end];
    }
}
