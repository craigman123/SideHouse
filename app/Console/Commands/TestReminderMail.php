<?php

namespace App\Console\Commands;

use App\Mail\BookingReminderMail;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a booking reminder email immediately for a given booking ID,
 * completely bypassing the paid/timing/reminder_sent_at checks in
 * SendUpcomingBookingReminders. Exists purely to isolate "does mail
 * transport actually work" from "does this booking match the reminder
 * query" while debugging SMTP config — NOT meant to run on a schedule.
 *
 * Usage: php artisan mail:test-reminder {booking_id}
 */
class TestReminderMail extends Command
{
    protected $signature = 'mail:test-reminder {booking_id}';

    protected $description = 'Send a booking reminder email immediately for testing, ignoring all window/status checks';

    public function handle(): int
    {
        $booking = Booking::with(['court', 'equipment.equipment'])->find($this->argument('booking_id'));

        if (! $booking) {
            $this->error("Booking #{$this->argument('booking_id')} not found.");
            return self::FAILURE;
        }

        if (! $booking->email) {
            $this->error("Booking #{$booking->id} has no email set — nothing to send to.");
            return self::FAILURE;
        }

        try {
            Mail::to($booking->email)->send(new BookingReminderMail($booking));
            $this->info("Sent test reminder for booking #{$booking->id} to {$booking->email}.");
            $this->info('Note: this did NOT set reminder_sent_at, so it will not interfere with the real cron logic.');
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
