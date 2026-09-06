@extends('layouts.app')

@section('title', 'Configuration | Side House')
@section('page-title', 'Configuration')
    
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin-schedule.css') }}">
    <link rel="stylesheet" href="{{ asset('css/manual-booking.css') }}">
@endpush

@section('content')
    <div class="schedule-page">

        <div class="schedule-header">
            <div>
                <h1 class="schedule-title">Configuration</h1>
                <p class="schedule-subtitle">Manage your site settings and preferences.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="schedule-flash schedule-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="schedule-flash schedule-flash-error">{{ session('error') }}</div>
        @endif

        {{-- ---------- Operating hours ---------- --}}
        <div class="schedule-panel">
            <div class="schedule-panel-header">
                <h2>Operating Hours</h2>
                <p class="schedule-panel-note">Applies to every court. Set Close before Open (e.g. Open 8 AM, Close 7 AM) for a window that crosses midnight.</p>
            </div>

            <form method="POST" action="{{ route('admin.configuration.hours.update') }}" class="schedule-hours-form">
                @csrf
                @method('PUT')

                <div class="schedule-field-grid">
                    <div class="schedule-field">
                        <label for="open_hour">Opens At</label>
                        <select name="open_hour" id="open_hour">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" @selected($settings->open_hour === $h)>
                                    {{ \Carbon\Carbon::createFromTime($h, 0)->format('g:i A') }}
                                </option>
                            @endfor
                        </select>
                        @error('open_hour') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="close_hour">Closes At</label>
                        <select name="close_hour" id="close_hour">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" @selected($settings->close_hour === $h)>
                                    {{ \Carbon\Carbon::createFromTime($h, 0)->format('g:i A') }}
                                </option>
                            @endfor
                        </select>
                        @error('close_hour') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="step_minutes">Slot Length</label>
                        <select name="step_minutes" id="step_minutes">
                            <option value="15" @selected($settings->step_minutes === 15)>15 minutes</option>
                            <option value="30" @selected($settings->step_minutes === 30)>30 minutes</option>
                            <option value="60" @selected($settings->step_minutes === 60)>60 minutes</option>
                        </select>
                        @error('step_minutes') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="min_duration_hours">Min Booking (hrs)</label>
                        <input type="number" name="min_duration_hours" id="min_duration_hours" min="1" max="24" value="{{ old('min_duration_hours', $settings->min_duration_hours) }}">
                        @error('min_duration_hours') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="max_duration_hours">Max Booking (hrs)</label>
                        <input type="number" name="max_duration_hours" id="max_duration_hours" min="1" max="24" value="{{ old('max_duration_hours', $settings->max_duration_hours) }}">
                        @error('max_duration_hours') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- ---------- Weekly closures ---------- --}}
                <div class="schedule-field schedule-field-weekdays">
                    <label>Closed Every Week On</label>
                    <p class="schedule-panel-note schedule-weekdays-note">Applies to every court, every week — on top of any one-off closed dates below.</p>

                    @php
                        $weekdayLabels = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
                        $checkedWeekdays = old('closed_weekdays', $settings->closed_weekdays);
                    @endphp

                    <div class="schedule-weekday-group">
                        @foreach ($weekdayLabels as $value => $label)
                            <label class="schedule-weekday-option">
                                <input
                                    type="checkbox"
                                    name="closed_weekdays[]"
                                    value="{{ $value }}"
                                    @checked(in_array($value, $checkedWeekdays))
                                >
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('closed_weekdays') <span class="schedule-field-error">{{ $message }}</span> @enderror
                </div>

                <div class="schedule-form-actions">
                    <button type="submit" class="btn btn-primary">Save Hours</button>
                </div>
            </form>
        </div>

        {{-- ---------- Peak / night pricing ---------- --}}
        <div class="schedule-panel">
            <div class="schedule-panel-header">
                <h2>Peak Pricing</h2>
                <p class="schedule-panel-note">Applies to every court. Adds a surcharge to bookings that fall inside this window — e.g. the higher electricity cost of running the night lights. Leave both times the same to turn peak pricing off. Set Ends before Starts (e.g. Starts 5 PM, Ends 6 AM) for a window that crosses midnight.</p>
            </div>

            <form method="POST" action="{{ route('admin.configuration.pricing.update') }}" class="schedule-hours-form">
                @csrf
                @method('PUT')

                <div class="schedule-field-grid">
                    <div class="schedule-field">
                        <label for="peak_start_hour">Starts At</label>
                        <select name="peak_start_hour" id="peak_start_hour">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" @selected(old('peak_start_hour', $settings->peak_start_hour) === $h)>
                                    {{ \Carbon\Carbon::createFromTime($h, 0)->format('g:i A') }}
                                </option>
                            @endfor
                        </select>
                        @error('peak_start_hour') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="peak_end_hour">Ends At</label>
                        <select name="peak_end_hour" id="peak_end_hour">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" @selected(old('peak_end_hour', $settings->peak_end_hour) === $h)>
                                    {{ \Carbon\Carbon::createFromTime($h, 0)->format('g:i A') }}
                                </option>
                            @endfor
                        </select>
                        @error('peak_end_hour') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="peak_adjustment_type">Surcharge Type</label>
                        <select name="peak_adjustment_type" id="peak_adjustment_type">
                            <option value="flat" @selected(old('peak_adjustment_type', $settings->peak_adjustment_type) === 'flat')>Flat amount (₱ per hour)</option>
                            <option value="percent" @selected(old('peak_adjustment_type', $settings->peak_adjustment_type) === 'percent')>Percent of hourly rate</option>
                        </select>
                        @error('peak_adjustment_type') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="peak_adjustment_value">Surcharge Amount</label>
                        <input type="number" name="peak_adjustment_value" id="peak_adjustment_value" min="0" max="1000" step="0.01" value="{{ old('peak_adjustment_value', $settings->peak_adjustment_value) }}">
                        @error('peak_adjustment_value') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="schedule-form-actions">
                    <button type="submit" class="btn btn-primary">Save Peak Pricing</button>
                </div>
            </form>
        </div>

        <div
            class="schedule-panel"
            id="manualBookingPanel"
            data-closure-dates="{{ $closures->map(fn ($c) => [
                'date' => $c->date->toDateString(),
                'court_id' => $c->court_id,
                'reason' => $c->reason,
            ])->toJson() }}"
            data-availability-url="{{ route('admin.configuration.availability') }}"
            data-open-hour="{{ $settings->open_hour }}"
            data-close-hour="{{ $settings->close_hour }}"
            data-step-minutes="{{ $settings->step_minutes }}"
            data-closed-weekdays="{{ implode(',', $settings->closed_weekdays) }}"
        >
            <div class="schedule-panel-header">
                <h2>Manual Booking</h2>
                <p class="schedule-panel-note">Book a court directly with no payment attached — walk-ins, phone reservations, comps. Goes straight to Confirmed.</p>
            </div>

            <form method="POST" action="{{ route('admin.configuration.manual-booking.store') }}" id="manual-booking-form">
                @csrf

                <div class="schedule-field-grid">
                    <div class="schedule-field">
                        <label for="mb_court">Court</label>
                        <select name="court_id" id="mb_court" required>
                            <option value="">Select a court</option>
                            @foreach ($courts as $court)
                                <option value="{{ $court->id }}">{{ $court->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="schedule-field">
                        <label for="mb_customer_name">Customer Name</label>
                        <input type="text" name="customer_name" id="mb_customer_name" maxlength="255" required>
                    </div>

                    <div class="schedule-field">
                        <label for="mb_contact_number">Contact Number</label>
                        <input type="text" name="contact_number" id="mb_contact_number" maxlength="32">
                    </div>

                    <div class="schedule-field">
                        <label for="mb_email">Email</label>
                        <input type="email" name="email" id="mb_email" maxlength="255">
                    </div>
                </div>

                <div class="schedule-field schedule-field-wide">
                    <label>Date / Time Slots</label>
                    <div id="mbSlotList" class="mb-slot-list"></div>
                    <p class="schedule-field-error" id="mbSlotsError" hidden>Add at least one date and time.</p>
                    <button type="button" id="mbAddSlotTrigger" class="btn btn-secondary btn-sm">+ Add Date &amp; Time</button>
                </div>

                <div id="mbHiddenSlots"></div>

                <div class="schedule-form-actions">
                    <button type="submit" class="btn btn-primary">Create Booking</button>
                </div>
            </form>
        </div>

        {{-- ---------- Closures ---------- --}}
        <div class="schedule-panel">
            <div class="schedule-panel-header">
                <h2>Closed Dates</h2>
                <p class="schedule-panel-note">Block a specific date — holidays, maintenance, tournaments, etc. Leave "Court" set to All Courts to close everything that day.</p>
            </div>

            <form
                method="POST"
                action="{{ route('admin.configuration.closures.store') }}"
                class="schedule-closure-form"
                id="closureForm"
                data-store-url="{{ route('admin.configuration.closures.store') }}"
                data-update-url-template="{{ route('admin.configuration.closures.update', ['closure' => '__ID__']) }}"
            >
                @csrf
                <input type="hidden" name="_method" id="closureFormMethod" value="">

                <div class="schedule-field-grid">
                    <div class="schedule-field">
                        <label for="closure_date_trigger">Date</label>

                        {{--
                            Custom calendar popover instead of the native
                            <input type="date"> — the hidden input below is
                            what actually submits with the form (same
                            "date" field name/shape the controller already
                            validates). data-existing-dates lets the JS mark
                            dates that already have a closure entry.
                        --}}
                        <div
                            class="sh-datepicker"
                            data-existing-dates="{{ $closures->pluck('date')->map(fn ($d) => $d->toDateString())->implode(',') }}"
                        >
                            <button
                                type="button"
                                class="sh-datepicker-trigger"
                                id="closure_date_trigger"
                                aria-haspopup="dialog"
                                aria-expanded="false"
                            >
                                <span class="sh-datepicker-value {{ old('date') ? '' : 'sh-datepicker-placeholder' }}">
                                    {{ old('date') ? \Carbon\Carbon::parse(old('date'))->format('M d, Y') : 'Select a date' }}
                                </span>
                                <svg class="sh-datepicker-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </button>

                            <input type="hidden" name="date" id="closure_date" value="{{ old('date') }}" required>

                            <div class="sh-datepicker-panel" role="dialog" hidden>
                                <div class="sh-datepicker-header">
                                    <button type="button" class="sh-datepicker-nav" data-dir="-1" aria-label="Previous month">&lsaquo;</button>
                                    <span class="sh-datepicker-month-label"></span>
                                    <button type="button" class="sh-datepicker-nav" data-dir="1" aria-label="Next month">&rsaquo;</button>
                                </div>
                                <div class="sh-datepicker-weekdays">
                                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                                </div>
                                <div class="sh-datepicker-grid"></div>
                            </div>
                        </div>

                        @error('date') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field">
                        <label for="closure_court">Court</label>
                        <select name="court_id" id="closure_court">
                            <option value="">All Courts</option>
                            @foreach ($courts as $court)
                                <option value="{{ $court->id }}" @selected(old('court_id') == $court->id)>{{ $court->name }}</option>
                            @endforeach
                        </select>
                        @error('court_id') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="schedule-field schedule-field-wide">
                        <label for="closure_reason">Reason (optional)</label>
                        <input type="text" name="reason" id="closure_reason" maxlength="255" placeholder="e.g. Holiday, resurfacing" value="{{ old('reason') }}">
                        @error('reason') <span class="schedule-field-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="schedule-form-actions">
                    <button type="submit" class="btn btn-primary" id="closureSubmitBtn">Add Closure</button>
                    <button type="button" class="btn btn-secondary" id="closureCancelEditBtn" style="display:none;">Cancel</button>
                </div>
            </form>

            <div class="schedule-closure-list">
                @forelse ($closures as $closure)
                    <div class="schedule-closure-row">
                        <div class="schedule-closure-info">
                            <span class="schedule-closure-date">{{ $closure->date->format('M d, Y (D)') }}</span>
                            <span class="schedule-closure-court">{{ $closure->court?->name ?? 'All Courts' }}</span>
                            @if ($closure->reason)
                                <span class="schedule-closure-reason">{{ $closure->reason }}</span>
                            @endif
                        </div>
                        <div class="schedule-closure-actions">
                            <button
                                type="button"
                                class="btn btn-secondary btn-sm sh-closure-edit-btn"
                                data-id="{{ $closure->id }}"
                                data-date="{{ $closure->date->toDateString() }}"
                                data-court-id="{{ $closure->court_id }}"
                                data-reason="{{ $closure->reason }}"
                            >Edit</button>
                            <form method="POST" action="{{ route('admin.configuration.closures.destroy', $closure) }}" onsubmit="return confirm('Remove this closure?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-secondary btn-sm">Remove</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="schedule-empty-note">No upcoming closures — the court follows regular hours every day.</p>
                @endforelse
            </div>
        </div>

    </div>

    {{-- Manual Booking: date picker --}}
<div class="mb-modal-overlay" id="mbCalendarModal">
    <div class="mb-modal-box">
        <div class="mb-modal-header">
            <h3>Select a Date</h3>
            <button type="button" class="mb-modal-close" id="mbCalendarClose" aria-label="Close">&times;</button>
        </div>
        <div class="mb-calendar">
            <div class="mb-calendar-header">
                <button type="button" class="mb-calendar-nav" id="mbCalPrev" aria-label="Previous month">&lsaquo;</button>
                <span id="mbCalMonthLabel"></span>
                <button type="button" class="mb-calendar-nav" id="mbCalNext" aria-label="Next month">&rsaquo;</button>
            </div>
            <div class="mb-calendar-weekdays">
                <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
            </div>
            <div class="mb-calendar-grid" id="mbCalendarGrid"></div>
        </div>
    </div>
</div>

    {{-- Manual Booking: time picker --}}
    <div class="mb-modal-overlay" id="mbTimeModal">
        <div class="mb-modal-box mb-time-box">
            <div class="mb-modal-header">
                <button type="button" class="mb-modal-back" id="mbBackToCalendar">&larr; Back to date</button>
                <button type="button" class="mb-modal-close" id="mbTimeClose" aria-label="Close">&times;</button>
            </div>
            <h3 id="mbTimeDateLabel" class="mb-time-date-label">Pick Your Hours</h3>
            <div class="mb-time-legend">
                <span><span class="mb-legend-swatch"></span> Available</span>
                <span><span class="mb-legend-swatch mb-legend-selected"></span> Selected</span>
                <span><span class="mb-legend-swatch mb-legend-booked"></span> Booked</span>
            </div>
            <div class="mb-time-slot-list" id="mbTimeSlotGrid"></div>
            <div class="mb-modal-actions">
                <button type="button" class="btn btn-secondary" id="mbTimeCancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="mbTimeAdd">Add</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-schedule.js') }}" defer></script>
    <script src="{{ asset('js/manual-booking.js') }}" defer></script>
    <script src="{{ asset('js/closure-edit.js') }}" defer></script>
@endpush