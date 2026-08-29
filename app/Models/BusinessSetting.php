<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BusinessSetting extends Model
{
    protected $fillable = [
        'open_hour',
        'close_hour',
        'step_minutes',
        'min_duration_hours',
        'max_duration_hours',
        'closed_weekdays',
        'peak_start_hour',
        'peak_end_hour',
        'peak_adjustment_type',
        'peak_adjustment_value',
    ];

    protected $casts = [
        'open_hour' => 'integer',
        'close_hour' => 'integer',
        'step_minutes' => 'integer',
        'min_duration_hours' => 'integer',
        'max_duration_hours' => 'integer',
        'closed_weekdays' => 'array',
        'peak_start_hour' => 'integer',
        'peak_end_hour' => 'integer',
        'peak_adjustment_value' => 'float',
    ];

    private const CACHE_KEY = 'business_settings.current';

    /**
     * The single settings row, cached — this gets read on every guest
     * and user booking request (availability, store, the landing page),
     * so it's worth not hitting the DB every time. firstOrCreate() means
     * a missing row (e.g. a fresh install that skipped the migration's
     * seed insert) still resolves to sane defaults instead of a crash.
     */
    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->firstOrCreate([], [
                'open_hour' => 8,
                'close_hour' => 7,
                'step_minutes' => 60,
                'min_duration_hours' => 1,
                'max_duration_hours' => 10,
                'closed_weekdays' => [],
            ]);
        });
    }

    /**
     * Days of week (0 = Sunday ... 6 = Saturday) the business is closed
     * every week. Normalized to an array here so callers never have to
     * null-check — a fresh/legacy row with no value stored still casts
     * to null via the array cast, not [].
     */
    public function getClosedWeekdaysAttribute($value): array
    {
        $decoded = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

        return array_values(array_unique(array_map('intval', $decoded)));
    }

    /**
     * True only when a full, non-degenerate peak window is configured —
     * every peak_* field set and the window isn't zero-length. Kept as a
     * single guard so callers don't have to remember all four conditions.
     */
    public function hasPeakPricing(): bool
    {
        return $this->peak_start_hour !== null
            && $this->peak_end_hour !== null
            && $this->peak_start_hour !== $this->peak_end_hour
            && in_array($this->peak_adjustment_type, ['flat', 'percent'], true)
            && $this->peak_adjustment_value > 0;
    }

    /**
     * Whether a given real clock hour (0-23) falls inside the peak window.
     * Same crosses-midnight convention as open_hour/close_hour elsewhere:
     * peak_end_hour <= peak_start_hour means the window wraps past midnight
     * (e.g. 17 → 6 covers 5 PM through 6 AM), so it's checked as an "or"
     * instead of a contiguous range.
     */
    public function isPeakHour(int $hour): bool
    {
        if (!$this->hasPeakPricing()) {
            return false;
        }

        if ($this->peak_end_hour > $this->peak_start_hour) {
            return $hour >= $this->peak_start_hour && $hour < $this->peak_end_hour;
        }

        return $hour >= $this->peak_start_hour || $hour < $this->peak_end_hour;
    }

    /**
     * Applies the peak surcharge to a single hour's base rate, if that
     * hour falls in the peak window. Callers should call this once PER
     * HOUR SLOT being priced (not once for a whole multi-hour booking),
     * since a booking spanning the peak boundary (e.g. 4 PM–6 PM with a
     * 5 PM peak start) has mixed rates across its own hours.
     */
    public function applyPeakAdjustment(float $hourlyRate, int $hour): float
    {
        if (!$this->isPeakHour($hour)) {
            return $hourlyRate;
        }

        return $this->peak_adjustment_type === 'percent'
            ? round($hourlyRate * (1 + $this->peak_adjustment_value / 100), 2)
            : round($hourlyRate + $this->peak_adjustment_value, 2);
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Any update from the admin Schedule page must be reflected on
        // the very next booking request, not up to an hour later.
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }
}