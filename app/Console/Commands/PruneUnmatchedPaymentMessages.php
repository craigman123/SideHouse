<?php

namespace App\Console\Commands;

use App\Models\UnmatchedPayment;
use Illuminate\Console\Command;

/**
 * Clears the raw SMS text stored on UnmatchedPayment rows once they're
 * old enough that they're no longer useful for manual reconciliation.
 * Real bank/GCash SMS bodies can carry a payer's name and a partial
 * phone number, so there's no reason to keep the full text sitting in
 * the database indefinitely — the amount, reference number, and match
 * status (which are what staff actually need to reconcile a payment)
 * stay untouched; only the `raw_message` column is nulled out.
 *
 * Default retention is 24 hours, comfortably longer than the longest
 * match/claim window in App\Support\PaymentWindows, so nothing that
 * could still legitimately need the raw text gets pruned early.
 *
 * Register in app/Console/Kernel.php:
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('payments:prune-raw-sms')->daily();
 *   }
 */
class PruneUnmatchedPaymentMessages extends Command
{
    protected $signature = 'payments:prune-raw-sms {--hours=24 : Age in hours after which raw_message is cleared}';

    protected $description = 'Clear stored raw SMS text on old UnmatchedPayment rows, keeping amount/reference/status for audit';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        $count = UnmatchedPayment::whereNotNull('raw_message')
            ->where('created_at', '<', now()->subHours($hours))
            ->update(['raw_message' => null]);

        $this->info("Cleared raw SMS text on {$count} unmatched payment record(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}