<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ConfigurationController extends Controller
{
    /**
     * Shows the current operating-hours settings plus every upcoming
     * closure. Past closures aren't shown here (nothing to manage about
     * them) — extend upcoming()/add a toggle in CourtClosure if a
     * history view is ever needed.
     */
    public function index(): View
    {
        $settings = BusinessSetting::current();
        $courts = Court::orderBy('name')->get();

        $closures = CourtClosure::with('court')
            ->upcoming()
            ->orderBy('date')
            ->get();

        $specificClosures = SpecificDateTimeClosure::orderBy('date')
            ->upcoming()
            ->get();

        return view('admin.configuration.index', compact(
            'settings', 
            'courts', 
            'closures',
            'specificClosures',
            ));
    }

    /**
     * Updates the single business_settings row. BusinessSetting::saved()
     * already clears the cache, so this takes effect on the very next
     * guest/user booking request.
     *
     * closed_weekdays comes in as a checkbox array (present only for
     * checked days, absent entirely if none are checked), so it's
     * normalized to [] rather than left null before saving.
     */
    public function updateHours(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'open_hour' => ['required', 'integer', 'between:0,23'],
            'close_hour' => ['required', 'integer', 'between:0,23'],
            'step_minutes' => ['required', 'integer', 'in:15,30,60'],
            'min_duration_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'max_duration_hours' => ['required', 'integer', 'min:1', 'max:24', 'gte:min_duration_hours'],
            'closed_weekdays' => ['nullable', 'array'],
            'closed_weekdays.*' => ['integer', 'between:0,6'],
        ]);

        $validated['closed_weekdays'] = array_values(array_unique(array_map(
            'intval',
            $validated['closed_weekdays'] ?? []
        )));

        $settings = BusinessSetting::current();
        $settings->update($validated);

        ActivityLogger::log(
            'schedule.hours_updated',
            auth()->user()->name . ' updated the booking hours configuration.',
            subject: $settings,
            properties: $validated,
        );

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', 'Operating hours updated.');
    }

    /**
     * Updates the peak-pricing fields on the single business_settings
     * row. Same cache-busting story as updateHours() — BusinessSetting::saved()
     * clears the cache, so this applies to the very next booking request.
     *
     * peak_start_hour === peak_end_hour is allowed on purpose: that's how
     * the admin turns peak pricing off (see BusinessSetting::hasPeakPricing()),
     * so it isn't rejected here.
     */
    public function updatePricing(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'peak_start_hour' => ['required', 'integer', 'between:0,23'],
            'peak_end_hour' => ['required', 'integer', 'between:0,23'],
            'peak_adjustment_type' => ['required', 'in:flat,percent'],
            'peak_adjustment_value' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $settings = BusinessSetting::current();
        $settings->update($validated);

        ActivityLogger::log(
            'schedule.pricing_updated',
            auth()->user()->name . ' updated the peak pricing configuration.',
            subject: $settings,
            properties: $validated,
        );

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', 'Peak pricing updated.');
    }

    /**
     * Adds closures for one or more dates — either store-wide (no court
     * selected) or scoped to a single court. Each selected date gets its
     * own CourtClosure row; dates that already have an entry are skipped
     * so duplicate-checking stays intact.
     */
    public function storeClosure(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'court_id'  => ['nullable', 'integer', 'exists:courts,id'],
            'dates'     => ['required', 'array', 'min:1'],
            'dates.*'   => ['required', 'date', 'after_or_equal:today'],
            'reason'    => ['nullable', 'string', 'max:255'],
        ]);

        $courtId = $validated['court_id'] ?? null;
        $reason  = $validated['reason'] ?? null;
        $dates   = array_unique($validated['dates']);

        $added   = 0;
        $skipped = 0;
        $lastClosure = null;

        foreach ($dates as $date) {
            $exists = CourtClosure::where('date', $date)
                ->where('court_id', $courtId)
                ->exists();

            if ($exists) { $skipped++; continue; }

            $lastClosure = CourtClosure::create([
                'court_id' => $courtId,
                'date'     => $date,
                'reason'   => $reason,
            ]);

            ActivityLogger::log(
                'schedule.closure_added',
                sprintf(
                    '%s closed %s on %s%s.',
                    auth()->user()->name,
                    $lastClosure->court?->name ?? 'all courts',
                    $lastClosure->date->format('M d, Y'),
                    $reason ? " ({$reason})" : '',
                ),
                subject: $lastClosure,
            );

            $added++;
        }

        if ($added === 0) {
            return redirect()
                ->route('admin.configuration.index')
                ->with('error', 'All selected dates already have a closure entry.');
        }

        $message = $added === 1
            ? 'Closure added.'
            : "{$added} closure(s) added." . ($skipped ? " {$skipped} skipped (already exist)." : '');

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', $message);
    }

    public function updateClosure(Request $request, CourtClosure $closure): RedirectResponse
    {
        $validated = $request->validate([
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $exists = CourtClosure::where('date', $validated['date'])
            ->where('court_id', $validated['court_id'] ?? null)
            ->where('id', '!=', $closure->id)
            ->exists();

        if ($exists) {
            return redirect()
                ->route('admin.configuration.index')
                ->with('error', 'That date already has a closure entry.');
        }

        $closure->update($validated);

        ActivityLogger::log(
            'schedule.closure_updated',
            sprintf(
                '%s updated the closure for %s on %s%s.',
                auth()->user()->name,
                $closure->court?->name ?? 'all courts',
                $closure->date->format('M d, Y'),
                $closure->reason ? " ({$closure->reason})" : '',
            ),
            subject: $closure,
            properties: $validated,
        );

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', 'Closure updated.');
    }

    public function destroyClosure(CourtClosure $closure): RedirectResponse
    {
        $description = sprintf(
            '%s reopened %s on %s.',
            auth()->user()->name,
            $closure->court?->name ?? 'all courts',
            $closure->date->format('M d, Y'),
        );

        $closure->delete();

        ActivityLogger::log('schedule.closure_removed', $description);

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', 'Closure removed.');
    }

    public function storeManualBooking(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*.date' => ['required', 'date', 'after_or_equal:today'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        $courtId = (int) $validated['court_id'];

        // Validate each slot's own logic + conflicts before touching the DB.
        foreach ($validated['slots'] as $i => $slot) {
            if ($slot['end_time'] <= $slot['start_time']) {
                return redirect()
                    ->route('admin.configuration.index')
                    ->with('error', 'Slot ' . ($i + 1) . ': end time must be after start time.');
            }

            $closed = CourtClosure::where('date', $slot['date'])
                ->where(fn ($q) => $q->whereNull('court_id')->orWhere('court_id', $courtId))
                ->exists();

            if ($closed) {
                return redirect()
                    ->route('admin.configuration.index')
                    ->with('error', "Slot " . ($i + 1) . " ({$slot['date']}) falls on a closed date for this court.");
            }

            $slotConflict = BookingSlot::where('court_id', $courtId)
                ->where('date', $slot['date'])
                ->where('start_time', '<', $slot['end_time'])
                ->where('end_time', '>', $slot['start_time'])
                ->whereHas('booking', fn ($q) => $q->whereNotIn('status', ['cancelled', 'expired']))
                ->exists();

            $legacyConflict = Booking::where('court_id', $courtId)
                ->where('date', $slot['date'])
                ->where('start_time', '<', $slot['end_time'])
                ->where('end_time', '>', $slot['start_time'])
                ->whereDoesntHave('slots')
                ->whereNotIn('status', ['cancelled', 'expired'])
                ->exists();

            if ($slotConflict || $legacyConflict) {
                return redirect()
                    ->route('admin.configuration.index')
                    ->with('error', "Slot " . ($i + 1) . " ({$slot['date']} {$slot['start_time']}–{$slot['end_time']}) overlaps an existing booking.");
            }
        }

        $booking = DB::transaction(function () use ($validated, $courtId) {
            $firstSlot = $validated['slots'][0];

            $booking = Booking::create([
                'customer_name' => $validated['customer_name'],
                'contact_number' => $validated['contact_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'court_id' => $courtId,
                'date' => $firstSlot['date'],
                'start_time' => $firstSlot['start_time'],
                'end_time' => $firstSlot['end_time'],
                'amount' => 0,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            foreach ($validated['slots'] as $slot) {
                $booking->slots()->create([
                    'court_id' => $courtId,
                    'date' => $slot['date'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'price' => 0,
                ]);
            }

            return $booking;
        });

        ActivityLogger::log(
            'schedule.manual_booking_created',
            sprintf(
                '%s created a no-payment booking for %s across %d slot(s).',
                auth()->user()->name,
                $booking->customer_name,
                count($validated['slots']),
            ),
            subject: $booking,
            properties: $validated,
        );

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', 'Booking created — no payment required.');
    }

    public function storeSpecificDateClosure(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dates' => ['required', 'array', 'min:1'],
            'dates.*' => ['required', 'date', 'after_or_equal:today'],
            'time_closing' => ['required', 'date_format:H:i'],
        ]);

        $dates = array_unique($validated['dates']);
        $added = 0;
        $skipped = 0;

        foreach ($dates as $date) {
            $exists = SpecificDateTimeClosure::where('date', $date)
                ->where('time_closing', $validated['time_closing'])
                ->exists();

            if ($exists) { $skipped++; continue; }

            $closure = SpecificDateTimeClosure::create([
                'date' => $date,
                'time_closing' => $validated['time_closing'],
            ]);

            ActivityLogger::log(
                'schedule.specific_date_time_closed_added',
                sprintf(
                    '%s added a specific date/time closure for %s at %s.',
                    auth()->user()->name,
                    $closure->date,
                    $closure->time_closing,
                ),
                subject: $closure,
            );

            $added++;
        }

        if ($added === 0) {
            return redirect()
                ->route('admin.configuration.index')
                ->with('error', 'All selected dates already have that closing time.');
        }

        $message = $added === 1
            ? 'Specific date/time closure added.'
            : "{$added} closure(s) added." . ($skipped ? " {$skipped} skipped (already exist)." : '');

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', $message);
    }

    public function editSpecificDateClosure(SpecificDateTimeClosure $closure): View
    {
        return view('admin.configuration.edit_specific_date_time_closure', compact('closure'));
    }

    public function destroySpecificDateClosure(SpecificDateTimeClosure $closure): RedirectResponse
    {
        $description = sprintf(
            '%s removed the specific date/time closure for %s at %s.',
            auth()->user()->name,
            $closure->date,
            $closure->time_closing,
        );

        $closure->delete();

        ActivityLogger::log('schedule.specific_date_time_closed_removed', $description);

        return redirect()
            ->route('admin.configuration.index')
            ->with('success', 'Specific date/time closure removed.');
    }

    /**
 * JSON availability for the Manual Booking date/time picker — mirrors
 * whatever the guest widget's own availability endpoint returns (I
 * didn't have that controller to compare against, so this is a fresh
 * query against the same tables). Worth merging into one shared query
 * later so the two can't drift apart.
 */
    public function availability(Request $request)
    {
        $validated = $request->validate([
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'date' => ['required', 'date'],
        ]);

        $courtId = (int) $validated['court_id'];
        $date = $validated['date'];

        $closure = CourtClosure::where('date', $date)
            ->where(fn ($q) => $q->whereNull('court_id')->orWhere('court_id', $courtId))
            ->first();

        $slotBooked = BookingSlot::where('court_id', $courtId)
            ->where('date', $date)
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', ['cancelled', 'expired']))
            ->get(['start_time', 'end_time']);

        $legacyBooked = Booking::where('court_id', $courtId)
            ->where('date', $date)
            ->whereDoesntHave('slots')
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->get(['start_time', 'end_time']);

        $booked = $slotBooked->concat($legacyBooked)->map(fn ($row) => [
            'start' => substr((string) $row->start_time, 0, 5),
            'end' => substr((string) $row->end_time, 0, 5),
        ])->values();

        return response()->json([
            'booked' => $booked,
            'closed' => (bool) $closure,
            'closed_reason' => $closure?->reason,
        ]);
    }
}