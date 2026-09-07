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

    protected $description = 'Check inbox for PayMongo maintenance notification emails, parse the maintenance window, and store or update it';

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

                // PayMongo sometimes sends a follow-up that changes the
                // time of an already-known date (e.g. "extended by one
                // hour") rather than announcing a brand new maintenance
                // day. Treat "same calendar date" as the same window and
                // update it in place, instead of accumulating duplicate
                // rows that could both end up blocking the picker.
                $windowDate = Carbon::parse($window['start'])->toDateString();

                $existing = PaymongoMaintenanceWindow::whereDate('start_at', $windowDate)->first();

                if ($existing) {
                    $existing->update([
                        'subject'  => $subject,
                        'start_at' => $window['start'],
                        'end_at'   => $window['end'],
                        'raw_body' => $body,
                    ]);
                    $this->info('Updated existing maintenance window for this date.');
                } else {
                    PaymongoMaintenanceWindow::create([
                        'subject'  => $subject,
                        'start_at' => $window['start'],
                        'end_at'   => $window['end'],
                        'raw_body' => $body,
                    ]);
                }

                Log::info('PayMongo maintenance window stored', [
                    'subject' => $subject,
                    'start'   => $window['start'],
                    'end'     => $window['end'],
                    'updated_existing' => (bool) $existing,
                ]);
            } else {
                $this->warn('Could not parse a maintenance window from this email — check the body format.');
                Log::warning('PayMongo maintenance email received but not parseable', [
                    'subject' => $subject,
                    'body'    => $body,
                ]);
            }

            $message->setFlag('Seen');
        }

        $client->disconnect();
    }

    /**
     * Parse the maintenance date and time range independently, since
     * PayMongo's follow-up/update emails wrap them in different
     * surrounding sentences (e.g. "...has been extended by one hour,
     * and will now run from 10:00 PM to 12:00 AM PHT..." instead of the
     * original "...(Friday), from 10:00 PM to 11:00 PM PHT..."). Matching
     * them separately means extra prose in between doesn't break parsing.
     */
    private function parseMaintenanceWindow(string $body): ?array
    {
        $datePattern = '/on\s+([A-Za-z]+ \d{1,2},\s*\d{4})\s*\([^)]+\)/';
        $timePattern = '/from\s+([\d:]+\s*[APap][Mm])\s+to\s+([\d:]+\s*[APap][Mm])\s*PHT/';

        if (!preg_match($datePattern, $body, $dateMatch)) {
            return null;
        }

        if (!preg_match($timePattern, $body, $timeMatch)) {
            return null;
        }

        [, $dateStr] = $dateMatch;
        [, $startTimeStr, $endTimeStr] = $timeMatch;

        try {
            $start = Carbon::parse("{$dateStr} {$startTimeStr}", 'Asia/Manila');
            $end = Carbon::parse("{$dateStr} {$endTimeStr}", 'Asia/Manila');

            // A window like "10:00 PM to 12:00 AM" crosses midnight —
            // the end time is technically the next calendar day, not
            // the same day at 00:00. If parsing put the end at or
            // before the start, it's a rollover: push it forward a day.
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return [
                'start' => $start->toDateTimeString(),
                'end'   => $end->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}