<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanWebhookPayloads extends Command
{
    protected $signature = 'webhook:clean {--days=30 : Keep payloads for this many days}';
    protected $description = 'Delete old webhook payloads from payment_webhook_events';

    public function handle()
    {
        $days = $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = DB::table('payment_webhook_events')
            ->where('created_at', '<', $cutoff)
            ->where('status', 'completed')
            ->delete();

        $this->info("Deleted {$deleted} old webhook payloads.");
        
        return Command::SUCCESS;
    }
}