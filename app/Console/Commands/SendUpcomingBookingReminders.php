<?php

namespace App\Console\Commands;

use App\Jobs\SendBookingReminderEmail;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Emails guests roughly an hour before their paid booking starts, using
 * the address they verified via Google Sign-In at booking time
 * (Booking::email — see GuestBookingController::verifyGoogleIdToken()).
 * Only paid bookings get a reminder; pending/cancelled ones don't, since
 * a pending booking might not even be a real reservation yet.
 *
 * NOTE: this previously filtered on status 'confirmed', but guest
 * bookings never reach that status — GuestBookingController moves them
 * from 'pending' straight to 'paid' (see updateReference()'s
 * `$booking->update(['status' => 'paid', 'confirmed_at' => now()])`).
 * That meant this query always matched zero rows and no guest ever got
 * an email reminder. Fixed to match the status bookings actually use.
 *
 * Runs frequently (every 5 minutes — see routes/console.php) and sends
 * to any confirmed booking whose start time has entered the reminder
 * window and hasn't been reminded yet, rather than only matching an
 * exact "60 minutes from now" instant — that would silently skip
 * bookings if the scheduler was ever a few minutes late or missed a
 * tick. reminder_sent_at is what actually prevents duplicates, not the
 * run cadence.
 *
 * A booking only qualifies once it's status=paid, confirmed_at is set,
 * has an email, and reminder_sent_at is still null. reminder_sent_at is
 * only written by SendBookingReminderEmail AFTER the email actually
 * sends successfully — so if a send fails (SMTP/API error, timeout,
 * etc.), the row stays null and this command will pick it up again on
 * the very next 5-minute tick, on top of the job's own 3 built-in
 * retries. Effectively: keep retrying every 5 minutes until it sends,
 * or until the booking's start time passes (isPast() below stops it).
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
 * Register in routes/console.php (alongside SendBookingReminders' own
 * in-app command — each needs its own name, see that file's note):
 *   Schedule::command('bookings:send-email-reminders')->everyFiveMinutes();
 */
class SendUpcomingBookingReminders extends Command
{
    protected $signature = 'bookings:send-email-reminders';

    protected $description = 'Email guests roughly one hour before their paid booking starts';

    // How far ahead of the start time a booking enters the reminder
    // window. Must stay well above the scheduler's run interval
    // (currently 5 minutes) or a booking could become "already past"
    // before ever being picked up.
    private const REMINDER_LEAD_MINUTES = 60;

    public function handle(): int
    {
        $sent = 0;

        Booking::where('status', 'paid')
            ->whereNotNull('confirmed_at')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('email')
            ->whereDate('date', '>=', now()->toDateString())
            ->whereDate('date', '<=', now()->addDay()->toDateString())
            ->with('court')
            ->get()
            ->each(function (Booking $booking) use (&$sent) {
                $start = Carbon::parse(
                    $booking->date->format('Y-m-d') . ' ' . $booking->start_time,
                    'Asia/Manila'
                );

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
                    // Just dispatch the job – do NOT mark reminder_sent_at here
                    SendBookingReminderEmail::dispatch($booking);

                    $sent++;
                    $this->info("Dispatched reminder job for booking #{$booking->id} ({$booking->email}).");
                } catch (\Throwable $e) {
                    // Leave reminder_sent_at null so the next run can try again
                    $this->error("Failed to dispatch reminder for booking #{$booking->id}: {$e->getMessage()}");
                }
            });

        if ($sent === 0) {
            $this->info('No bookings currently due for a reminder.');
        }

        return self::SUCCESS;
    }
}