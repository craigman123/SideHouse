<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;

class PruneRequestLogs extends Command
{
    protected $signature = 'logs:prune {--days=20 : Keep request logs for this many days}';
    protected $description = 'Delete request_logs rows older than the given number of days';

    private const CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $deletedInChunk = RequestLog::where('created_at', '<', $cutoff)
                ->oldest('created_at')
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $deleted += $deletedInChunk;
        } while ($deletedInChunk === self::CHUNK_SIZE);

        $this->info("Deleted {$deleted} request log rows older than {$days} days.");
        return Command::SUCCESS;
    }
}