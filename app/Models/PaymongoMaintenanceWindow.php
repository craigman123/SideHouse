<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class PaymongoMaintenanceWindow extends Model
{
    protected $fillable = ['subject', 'start_at', 'end_at', 'raw_body'];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    private const CACHE_KEY = 'paymongo_maintenance.upcoming';
    private const CACHE_TTL_MINUTES = 5;

    /**
     * Is there a maintenance window covering right now?
     */
    public static function isCurrentlyActive(): bool
    {
        $now = Carbon::now('Asia/Manila');

        return static::where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->exists();
    }

    /**
     * Every window that hasn't ended yet, cached briefly. Short TTL
     * (rather than rememberForever like BusinessSetting) because these
     * rows are inserted by the automated email-check command, not
     * through an admin form with a save-hook to bust the cache
     * immediately — see App\Console\Commands\CheckPaymongoMaintenance.
     */
    public static function currentAndUpcoming()
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            return static::where('end_at', '>=', now())->get(['start_at', 'end_at']);
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }
}