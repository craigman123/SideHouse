<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('admin.configuration.index', compact('settings', 'courts', 'closures'));
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
     * Adds a closure for one date — either store-wide (no court
     * selected) or scoped to a single court.
     */
    public function storeClosure(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $exists = CourtClosure::where('date', $validated['date'])
            ->where('court_id', $validated['court_id'] ?? null)
            ->exists();

        if ($exists) {
            return redirect()
                ->route('admin.configuration.index')
                ->with('error', 'That date already has a closure entry.');
        }

        $closure = CourtClosure::create($validated);

        ActivityLogger::log(
            'schedule.closure_added',
            sprintf(
                '%s closed %s on %s%s.',
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
            ->with('success', 'Closure added.');
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
}