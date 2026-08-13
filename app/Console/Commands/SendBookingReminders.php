<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Support\NotificationService;
use Illuminate\Console\Command;

/**
 * Notifies a user their booking is coming up soon. Runs on a schedule
 * (see app/Console/Kernel.php registration below) and looks for paid
 * bookings starting within the next REMINDER_WINDOW_MINUTES that haven't
 * been reminded about yet — reminder_sent_at gates that, so this is safe
 * to run frequently without spamming duplicate reminders.
 *
 * Only bookings with a user_id get reminded (guest bookings have no
 * account to notify) — same "no account, no notification" rule as
 * NotificationService::notify()'s null check.
 *
 * Register in app/Console/Kernel.php:
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('bookings:send-reminders')->everyFiveMinutes();
 *   }
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Notify users whose paid booking is starting soon';

    // How far ahead of a booking's start time to send the reminder. Kept
    // wider than the scheduler's run interval (5 min) so a booking can't
    // slip through the gap between two runs without ever falling inside
    // the window.
    private const REMINDER_WINDOW_MINUTES = 60;

    public function handle(): int
    {
        $now = now();
        $windowEnd = $now->copy()->addMinutes(self::REMINDER_WINDOW_MINUTES);

        $bookings = Booking::where('status', 'paid')
            ->whereNotNull('user_id')
            ->whereNull('reminder_sent_at')
            ->whereDate('date', $now->toDateString())
            ->with('court')
            ->get()
            ->filter(function ($booking) use ($now, $windowEnd) {
                // start_time is a plain time-of-day column, not a
                // timestamp — combine it with the booking's own date
                // (Asia/Manila, same reasoning as UserDashboardController)
                // before comparing against "now" and the reminder window.
                $startsAt = \Carbon\Carbon::parse(
                    $booking->date->format('Y-m-d') . ' ' . $booking->start_time,
                    'Asia/Manila'
                );

                return $startsAt->between($now, $windowEnd);
            });

        foreach ($bookings as $booking) {
            $startLabel = \Carbon\Carbon::parse($booking->start_time)->format('g:i A');
            $courtLabel = $booking->court->name ?? "Court {$booking->court_id}";

            NotificationService::bookingReminder(
                $booking->user_id,
                $booking->id,
                'Your court time is coming up',
                "{$courtLabel} starts at {$startLabel} today — see you soon!",
            );

            $booking->update(['reminder_sent_at' => now()]);
            $this->info("Reminded booking #{$booking->id} (starts {$startLabel}).");
        }

        if ($bookings->isEmpty()) {
            $this->info('No bookings due for a reminder right now.');
        }

        return self::SUCCESS;
    }
}
