@php
    $weeklyDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $closedWeekdays = $businessSettings->closed_weekdays ?? [];
@endphp
<section class="specific-closures" data-specific-closures>
    <h2>Weekly closures</h2>
    <p>Close a court at the selected time every week. Existing bookings and events are not changed.</p>
    <form class="specific-closure-form" data-closure-form>
        @csrf
        <fieldset class="specific-closure-weekdays">
            <legend>Weekdays <span>Select at least one day</span></legend>
            <div class="specific-closure-quick-select">
                <button type="button" class="specific-closure-quick-button" data-weekday-preset="weekdays">Weekdays</button>
                <button type="button" class="specific-closure-quick-button" data-weekday-preset="weekend">Weekend</button>
                <button type="button" class="specific-closure-quick-button" data-weekday-preset="all">All</button>
            </div>
            <div class="specific-closure-day-chips">
                @foreach ($weeklyDays as $weekday => $label)
                    @php($isClosed = in_array($weekday, $closedWeekdays, true))
                    <label class="specific-closure-day-chip {{ $isClosed ? 'is-disabled' : '' }}">
                        <input type="checkbox" name="weekdays[]" value="{{ $weekday }}" {{ $isClosed ? 'disabled' : '' }}>
                        <span>{{ $label }}</span>
                        @if ($isClosed)<small>Closed all day</small>@endif
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="specific-closure-fields">
            <label>Start time<select name="start_time" required></select></label>
            <label>End time<select name="end_time" required></select></label>
            <label>Court<select name="court_id"><option value="">All courts</option>@foreach ($courts as $court)<option value="{{ $court->id }}">{{ $court->name }}</option>@endforeach</select></label>
            <label>Reason / note<input type="text" name="reason" maxlength="5000"></label>
        </div>
        <p class="specific-closure-hours-note" data-closure-hours-note hidden></p>
        <p class="specific-closure-preview" data-closure-preview aria-live="polite" hidden></p>
        <p class="specific-closure-message" data-closure-message role="status" aria-live="polite" hidden></p>
        <div class="specific-closure-conflicts" data-closure-conflicts hidden></div>
        <div class="specific-closure-form-actions court-block-modal-actions">
            <button class="court-block-secondary btn-ghost-danger" type="reset">Reset</button>
            <button class="court-block-primary" type="submit" disabled>Add weekly closure</button>
        </div>
    </form>
    <div class="specific-closure-upcoming">
        <h3>Active weekly closures</h3>
        <div data-closure-list aria-live="polite"><div class="skeleton skeleton-line" aria-hidden="true"></div></div>
    </div>
</section>

@once
    @push('styles')<link rel="stylesheet" href="{{ asset('css/shared/skeletons.css') . '?v=' . filemtime(public_path('css/shared/skeletons.css')) }}"><link rel="stylesheet" href="{{ asset('css/shared/specific-date-closures.css') . '?v=' . filemtime(public_path('css/shared/specific-date-closures.css')) }}">@endpush
    @push('scripts')<script src="{{ asset('js/shared/specific-date-closures.js') . '?v=' . filemtime(public_path('js/shared/specific-date-closures.js')) }}" defer></script>@endpush
@endonce
