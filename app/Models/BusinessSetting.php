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
    ];

    protected $casts = [
        'open_hour' => 'integer',
        'close_hour' => 'integer',
        'step_minutes' => 'integer',
        'min_duration_hours' => 'integer',
        'max_duration_hours' => 'integer',
        'closed_weekdays' => 'array',
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
                'step_minutes' => 30,
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