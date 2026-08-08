<?php

namespace App\Console\Commands;

use App\Mail\BookingReminderMail;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emails guests roughly an hour before their confirmed booking starts,
 * using the address they verified via Google Sign-In at booking time
 * (Booking::email — see GuestBookingController::verifyGoogleIdToken()).
 * Only confirmed bookings get a reminder; pending/cancelled ones don't,
 * since a pending booking might not even be a real reservation yet.
 *
 * Runs frequently (every 5 minutes — see routes/console.php) and sends
 * to any confirmed booking whose start time has entered the reminder
 * window and hasn't been reminded yet, rather than only matching an
 * exact "60 minutes from now" instant — that would silently skip
 * bookings if the scheduler was ever a few minutes late or missed a
 * tick. reminder_sent_at is what actually prevents duplicates, not the
 * run cadence.
 *
 * CAVEAT: like monthlyStats() in GuestBookingController, this assumes
 * Booking::date is the true calendar date of the start time. For an
 * overnight booking whose selected hour rolled past midnight (handled
 * in GuestBookingController::store() by pushing that slot's Carbon
 * instance forward a day), the stored `date` column still holds the
 * originally-requested date, not the shifted one — so a post-midnight
 * slot's computed start time here could be off by a day. This is a
 * pre-existing quirk in how bookings are stored, not something new to
 * this command; flagging it here in case reminders for overnight slots
 * look wrong.
 *
 * Register in routes/console.php:
 *   Schedule::command('bookings:send-reminders')->everyFiveMinutes();
 */
class SendUpcomingBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Email guests roughly one hour before their confirmed booking starts';

    // How far ahead of the start time a booking enters the reminder
    // window. Must stay well above the scheduler's run interval
    // (currently 5 minutes) or a booking could become "already past"
    // before ever being picked up.
    private const REMINDER_LEAD_MINUTES = 60;

    public function handle(): int
    {
        $sent = 0;

        Booking::where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('email')
            ->whereDate('date', '>=', now()->toDateString())
            ->whereDate('date', '<=', now()->addDay()->toDateString())
            ->with('court')
            ->get()
            ->each(function (Booking $booking) use (&$sent) {
                $start = Carbon::parse($booking->date . ' ' . $booking->start_time);

                // Not yet inside the reminder window, or already
                // started/passed — leave reminder_sent_at null so a
                // later run can still pick it up (or skip it forever
                // once it's in the past, which is fine: nobody needs a
                // reminder for a slot that already happened).
                // diffInMinutes() is unsigned here on purpose — Carbon's
                // signed-diff convention differs enough between versions
                // that it's not worth relying on; isPast() covers the
                // "already happened" side explicitly instead.
                if ($start->isPast() || now()->diffInMinutes($start) > self::REMINDER_LEAD_MINUTES) {
                    return;
                }

                try {
                    Mail::to($booking->email)->send(new BookingReminderMail($booking));
                    $booking->update(['reminder_sent_at' => now()]);
                    $sent++;
                    $this->info("Sent reminder for booking #{$booking->id} ({$booking->email}).");
                } catch (\Throwable $e) {
                    // Don't mark reminder_sent_at on failure — leave it
                    // null so the next run retries instead of silently
                    // losing the reminder.
                    $this->error("Failed to send reminder for booking #{$booking->id}: {$e->getMessage()}");
                }
            });

        if ($sent === 0) {
            $this->info('No bookings currently due for a reminder.');
        }

        return self::SUCCESS;
    }
}
