<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MetricsService
{
    public function combined(): array
    {
        return Cache::remember('system-monitor:combined', now()->addSeconds(5), function () {
            return [
                'database' => $this->databaseMetrics(),
                'app' => $this->appMetrics(),
                'traffic' => $this->trafficMetrics(),
            ];
        });
    }

    private function databaseMetrics(): array
    {
        $driver = DB::getDriverName();

        try {
            if ($driver === 'mysql') {
                $status = collect(DB::select("SHOW STATUS WHERE Variable_name IN ('Threads_connected','Questions')"))
                    ->mapWithKeys(fn ($row) => [(string) $row->Variable_name => (int) $row->Value]);

                $questions = $status->get('Questions');
                $queriesPerMinute = $this->mysqlQueriesPerMinute($questions);

                $avgQueryTime = null;
                try {
                    $row = DB::selectOne(
                        "SELECT AVG(TIMER_WAIT) / 1000000000 AS avg_ms
                         FROM performance_schema.events_statements_summary_by_digest
                         WHERE SCHEMA_NAME = DATABASE()"
                    );
                    $avgQueryTime = isset($row->avg_ms) ? round((float) $row->avg_ms, 2) : null;
                } catch (Throwable) {
                    $avgQueryTime = null;
                }

                return [
                    'active_connections' => $status->get('Threads_connected'),
                    'queries_per_minute' => $queriesPerMinute,
                    'average_query_time_ms' => $avgQueryTime,
                ];
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne(
                    'SELECT count(*) AS active_connections
                     FROM pg_stat_activity
                     WHERE datname = current_database()'
                );

                return [
                    'active_connections' => isset($row->active_connections) ? (int) $row->active_connections : null,
                    'queries_per_minute' => null,
                    'average_query_time_ms' => null,
                ];
            }
        } catch (Throwable) {
            return [
                'active_connections' => null,
                'queries_per_minute' => null,
                'average_query_time_ms' => null,
            ];
        }

        return [
            'active_connections' => null,
            'queries_per_minute' => null,
            'average_query_time_ms' => null,
        ];
    }

    private function mysqlQueriesPerMinute(?int $questions): ?int
    {
        if ($questions === null) {
            return null;
        }

        $now = microtime(true);
        $baseline = Cache::get('system-monitor:mysql-questions-baseline');

        if (! is_array($baseline) || ! isset($baseline['questions'], $baseline['time'])) {
            Cache::put('system-monitor:mysql-questions-baseline', [
                'questions' => $questions,
                'time' => $now,
            ], now()->addMinutes(10));
            return 0;
        }

        $elapsedMinutes = ($now - (float) $baseline['time']) / 60;
        if ($elapsedMinutes <= 0) {
            return 0;
        }

        $delta = $questions - (int) $baseline['questions'];
        if ($delta < 0) {
            $delta = 0;
        }

        Cache::put('system-monitor:mysql-questions-baseline', [
            'questions' => $questions,
            'time' => $now,
        ], now()->addMinutes(10));

        return (int) round($delta / $elapsedMinutes);
    }

    private function appMetrics(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

        return [
            'php_memory_bytes' => memory_get_usage(true),
            'server_load' => is_array($load) ? round((float) $load[0], 2) : null,
            'average_response_time_ms' => $this->averageRecentResponseTime(),
        ];
    }

    private function trafficMetrics(): array
    {
        $minute = (int) floor(microtime(true) / 60);
        $requestsPerMinute = (int) Cache::get("system-monitor:traffic:{$minute}:requests", 0);
        $errors = 0;

        for ($i = 0; $i < 5; $i++) {
            $bucket = $minute - $i;
            $errors += (int) Cache::get("system-monitor:traffic:{$bucket}:errors", 0);
        }

        return [
            'requests_per_minute' => $requestsPerMinute,
            'error_count_5m' => $errors,
        ];
    }

    private function averageRecentResponseTime(): ?float
    {
        $minute = (int) floor(microtime(true) / 60);
        $sum = 0;
        $count = 0;

        for ($i = 0; $i < 5; $i++) {
            $bucket = $minute - $i;
            $sum += (int) Cache::get("system-monitor:traffic:{$bucket}:duration-sum", 0);
            $count += (int) Cache::get("system-monitor:traffic:{$bucket}:duration-count", 0);
        }

        return $count > 0 ? round($sum / $count, 2) : null;
    }
}
