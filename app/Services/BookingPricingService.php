<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use Carbon\CarbonImmutable;

class BookingPricingService
{
    public function clientRules(): array
    {
        $settings = BusinessSetting::current();
        $courts = Court::query()->orderBy('id')->get(['id', 'hourly_rate'])->map(fn (Court $court) => [
            'id' => (int) $court->id, 'hourly_rate' => (float) $court->hourly_rate,
        ])->values()->all();
        $openPlay = CourtBlock::query()->where('type', 'open_play')->orderBy('id')->get([
            'id', 'court_id', 'block_date', 'start_time', 'end_time', 'price_per_player', 'updated_at',
        ])->map(fn (CourtBlock $block) => [
            'id' => (int) $block->id, 'court_id' => $block->court_id === null ? null : (int) $block->court_id,
            'date' => $block->block_date?->toDateString(), 'start' => substr((string) $block->start_time, 0, 5),
            'end' => substr((string) $block->end_time, 0, 5), 'price_per_player' => (float) $block->price_per_player,
            'updated_at' => $block->updated_at?->format('U.u'),
        ])->values()->all();
        $rules = [
            'step_minutes' => (int) ($settings->step_minutes ?: 60),
            'peak_start_hour' => $settings->peak_start_hour,
            'peak_end_hour' => $settings->peak_end_hour,
            'peak_adjustment_type' => $settings->peak_adjustment_type,
            'peak_adjustment_value' => $settings->peak_adjustment_value === null ? null : (float) $settings->peak_adjustment_value,
            'courts' => $courts,
            'open_play' => $openPlay,
        ];
        $rules['version'] = hash('sha256', json_encode($rules, JSON_THROW_ON_ERROR));

        return $rules;
    }

    /** @return array{total: float, has_open_play: bool} */
    public function interval(int $courtId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return self::calculate($this->clientRules(), $courtId, $start, $end);
    }

    /** Shared rules algorithm; mirrored by public/js/shared/booking-price-calculator.js. */
    public static function calculate(array $rules, int $courtId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $court = collect($rules['courts'] ?? [])->firstWhere('id', $courtId);
        if (! $court) throw new \InvalidArgumentException('Court price rules are missing.');
        $step = max(1, (int) ($rules['step_minutes'] ?? 60));
        $prices = [];
        $hasOpenPlay = false;
        for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addMinutes($step)) {
            $minutes = min($step, $cursor->diffInMinutes($end));
            $slotEnd = $cursor->addMinutes($minutes);
            $event = collect($rules['open_play'] ?? [])->first(function (array $event) use ($courtId, $cursor, $slotEnd) {
                $eventStart = CarbonImmutable::createFromFormat('!Y-m-d H:i', $event['date'].' '.$event['start']);
                $eventEnd = CarbonImmutable::createFromFormat('!Y-m-d H:i', $event['date'].' '.$event['end']);
                if ($eventEnd->lessThanOrEqualTo($eventStart)) $eventEnd = $eventEnd->addDay();
                return ($event['court_id'] === null || (int) $event['court_id'] === $courtId)
                    && $cursor->greaterThanOrEqualTo($eventStart) && $slotEnd->lessThanOrEqualTo($eventEnd);
            });
            if ($event) {
                $hasOpenPlay = true;
                $prices[] = (float) $event['price_per_player'];
                continue;
            }
            $hour = (int) $cursor->format('G');
            $peakStart = $rules['peak_start_hour'] ?? null;
            $peakEnd = $rules['peak_end_hour'] ?? null;
            $value = (float) ($rules['peak_adjustment_value'] ?? 0);
            $type = $rules['peak_adjustment_type'] ?? null;
            $peak = $peakStart !== null && $peakEnd !== null && $peakStart !== $peakEnd && $value > 0
                && in_array($type, ['flat', 'percent'], true)
                && ($peakEnd > $peakStart ? $hour >= $peakStart && $hour < $peakEnd : $hour >= $peakStart || $hour < $peakEnd);
            $rate = (float) $court['hourly_rate'];
            if ($peak) $rate = $type === 'percent' ? round($rate * (1 + $value / 100), 2) : round($rate + $value, 2);
            $prices[] = round($rate * ($minutes / 60), 2);
        }
        return ['total' => round(array_sum($prices), 2), 'has_open_play' => $hasOpenPlay, 'slot_prices' => $prices];
    }

    public static function movedAmount(float $currentAmount, float $oldSlotTotal, float $newSlotTotal): float
    {
        return round($currentAmount - $oldSlotTotal + $newSlotTotal, 2);
    }
}
