<?php

namespace App\Console\Commands;

use App\Models\PaymongoMaintenanceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;
use Carbon\Carbon;

class CheckPaymongoMaintenance extends Command
{
    protected $signature = 'app:check-paymongo-maintenance';

    protected $description = 'Check inbox for PayMongo maintenance notification emails and parse the maintenance window';

    public function handle()
    {
        $client = Client::account('default');
        $client->connect();

        $folder = $client->getFolder('INBOX');

        $messages = $folder->query()
            ->unseen()
            ->from('support@paymongo.com')
            ->subject('Maintenance')
            ->get();

        if ($messages->count() === 0) {
            $this->info('No new PayMongo maintenance emails found.');
            $client->disconnect();
            return;
        }

        foreach ($messages as $message) {
            $subject = $message->getSubject();
            $body = $message->getTextBody() ?: strip_tags($message->getHTMLBody());

            $this->info("Found maintenance email: {$subject}");

            $window = $this->parseMaintenanceWindow($body);

            if ($window) {
                $this->info("Parsed window: {$window['start']} to {$window['end']}");

                PaymongoMaintenanceWindow::create([
                    'subject'  => $subject,
                    'start_at' => $window['start'],
                    'end_at'   => $window['end'],
                    'raw_body' => $body,
                ]);

                Log::info('PayMongo maintenance window stored', [
                    'subject' => $subject,
                    'start' => $window['start'],
                    'end' => $window['end'],
                ]);
            } else {
                $this->warn('Could not parse a maintenance window from this email — check the body format.');
                Log::warning('PayMongo maintenance email received but not parseable', [
                    'subject' => $subject,
                    'body' => $body,
                ]);
            }

            $message->setFlag('Seen');
        }

        $client->disconnect();
    }

    /**
     * Parse a sentence like:
     * "...maintenance activity on September 11, 2026 (Friday), from 10:00 PM to 11:00 PM PHT..."
     */
    private function parseMaintenanceWindow(string $body): ?array
    {
        $pattern = '/on\s+([A-Za-z]+ \d{1,2},\s*\d{4})\s*\([^)]+\),\s*from\s+([\d:]+\s*[APap][Mm])\s+to\s+([\d:]+\s*[APap][Mm])\s*PHT/';

        if (!preg_match($pattern, $body, $matches)) {
            return null;
        }

        [, $dateStr, $startTimeStr, $endTimeStr] = $matches;

        try {
            $start = Carbon::parse("{$dateStr} {$startTimeStr}", 'Asia/Manila');
            $end = Carbon::parse("{$dateStr} {$endTimeStr}", 'Asia/Manila');

            return [
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}