<section class="specific-closures" data-specific-closures>
    <h2>Specific date and time closure</h2>
    <p>Close a court for a selected operating-hours interval. Overlapping bookings and events are not changed.</p>
    <form class="specific-closure-form" data-closure-form>
        @csrf
        <div class="specific-closure-fields">
            <label>Start date<input type="date" name="start_date" value="{{ $businessSettings->today }}" min="{{ $businessSettings->today }}" required></label>
            <label>End date<input type="date" name="end_date" value="{{ $businessSettings->today }}" min="{{ $businessSettings->today }}" required></label>
            <label>Start time<select name="start_time" required></select></label>
            <label>End time<select name="end_time" required></select></label>
            <label>Court<select name="court_id"><option value="">All courts</option>@foreach ($courts as $court)<option value="{{ $court->id }}">{{ $court->name }}</option>@endforeach</select></label>
            <label>Reason / note<input type="text" name="reason" maxlength="5000"></label>
        </div>
        <p class="specific-closure-preview" data-closure-preview aria-live="polite"></p>
        <p class="specific-closure-message" data-closure-message role="status" aria-live="polite"></p>
        <div class="specific-closure-conflicts" data-closure-conflicts hidden></div>
        <div class="court-block-modal-actions" style="justify-content: flex-start;">
            <button class="court-block-primary" type="submit">Add closure</button>
        </div>
    </form>
    <div class="specific-closure-upcoming">
        <h3>Upcoming closures</h3>
        <div data-closure-list aria-live="polite"><div class="skeleton skeleton-line" aria-hidden="true"></div></div>
    </div>
</section>

@once
    @push('styles')<link rel="stylesheet" href="{{ asset('css/shared/skeletons.css') . '?v=' . filemtime(public_path('css/shared/skeletons.css')) }}"><link rel="stylesheet" href="{{ asset('css/shared/specific-date-closures.css') . '?v=' . filemtime(public_path('css/shared/specific-date-closures.css')) }}">@endpush
    @push('scripts')<script src="{{ asset('js/shared/specific-date-closures.js') . '?v=' . filemtime(public_path('js/shared/specific-date-closures.js')) }}" defer></script>@endpush
@endonce
