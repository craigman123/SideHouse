@extends('layouts.user')

@section('page-class', 'user-book-court-page')
@section('title', 'Book a Court | Side House')
@section('page-title', 'Book a Court')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/landing-book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/book-court.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-booking-flow.css') }}">
@endpush

@section('content')

    <div class="panel">
        <h2>Available <span class="book-court-title-accent">Courts</span></h2><span class="count-badge">{{ $courts->count() }} Court(s) Available</span>
        <p class="panel-subtext">Tap a court to see its details, then pick a date and time to book it.</p>

        <div
            class="court-grid"
            id="courtGrid"
            data-availability-url="{{ route('book.availability') }}"
            data-equipment-url="{{ route('book.equipment-availability') }}"
            data-store-url="{{ route('book.store') }}"
            data-status-url-template="{{ route('book.status', ['booking' => '__ID__']) }}"
            data-waiting-url-template="{{ route('book.waiting', ['booking' => '__ID__']) }}"
            data-cancel-url-template="{{ route('user.bookings.cancel', ['booking' => '__ID__']) }}"
            data-open-hour="{{ $openHour }}"
            data-close-hour="{{ $closeHour }}"
            data-min-duration="{{ $minDuration }}"
            data-max-duration="{{ $maxDuration }}"
            data-step-minutes="{{ $stepMinutes }}"
            data-user-phone="{{ $userPhone }}"
            data-closed-weekdays="{{ implode(',', $closedWeekdays ?? []) }}"
            data-closure-dates="{{ ($closureDates ?? collect())->implode(',') }}"
            data-court-closures="{{ $courtClosures ?? '' }}"
            {{-- Peak/night pricing (admin Configuration page) — blank
                 attributes when no peak window is configured, which
                 book.js treats as "peak pricing off". Display-only: the
                 running total here is just a preview, the server
                 (User_UserController::storeBooking()) always recomputes
                 and charges the real amount. --}}
            data-peak-start-hour="{{ $peakStartHour ?? '' }}"
            data-peak-end-hour="{{ $peakEndHour ?? '' }}"
            data-peak-adjustment-type="{{ $peakAdjustmentType ?? '' }}"
            data-peak-adjustment-value="{{ $peakAdjustmentValue ?? '' }}"
        >
            @forelse ($courts as $court)
                <button
                    type="button"
                    class="court-card court-card-clickable"
                    data-id="{{ $court->id }}"
                    data-name="{{ $court->name }}"
                    data-type="{{ $court->surface_type ?? '—' }}"
                    data-length="{{ $court->length }}"
                    data-width="{{ $court->width }}"
                    data-price="{{ $court->hourly_rate }}"
                >
                    <div class="court-card-graphic" aria-hidden="true">
                        <svg viewBox="0 0 120 160" xmlns="http://www.w3.org/2000/svg">
                            <rect x="2" y="2" width="116" height="156" rx="6" class="court-mini-surface" />
                            <rect x="12" y="12" width="96" height="136" class="court-mini-boundary" />
                            <line x1="12" y1="80" x2="108" y2="80" class="court-mini-line court-mini-net" />
                            <line x1="12" y1="48" x2="108" y2="48" class="court-mini-line" />
                            <line x1="12" y1="112" x2="108" y2="112" class="court-mini-line" />
                            <line x1="60" y1="12" x2="60" y2="148" class="court-mini-line" />
                        </svg>
                    </div>
                    <div class="court-card-body">
                        <h3>{{ $court->name }}</h3>
                        <span class="court-type-badge">{{ $court->surface_type ?? 'Court' }}</span>
                        <p class="court-dimensions">{{ $court->length }}m &times; {{ $court->width }}m</p>
                        <p class="court-price">&#8369;{{ number_format($court->hourly_rate, 0) }} <span>/ hour</span></p>
                    </div>
                </button>
            @empty
                <p class="empty-state">No courts available right now.</p>
            @endforelse
        </div>
    </div>

    {{-- Modal 1: court info --}}
    <div class="modal-overlay" id="courtInfoModal">
        <div class="modal-box modal-box-lg">
            <div class="modal-header">
                <h3 id="modalCourtName">Court</h3>
                <button type="button" class="modal-close" id="courtInfoModalClose" aria-label="Close">&times;</button>
            </div>

            <div class="court-modal-graphic" aria-hidden="true">
                <svg viewBox="0 0 120 160" xmlns="http://www.w3.org/2000/svg">
                    <rect x="2" y="2" width="116" height="156" rx="6" class="court-mini-surface" />
                    <rect x="12" y="12" width="96" height="136" class="court-mini-boundary" />
                    <line x1="12" y1="80" x2="108" y2="80" class="court-mini-line court-mini-net" />
                    <line x1="12" y1="48" x2="108" y2="48" class="court-mini-line" />
                    <line x1="12" y1="112" x2="108" y2="112" class="court-mini-line" />
                    <line x1="60" y1="12" x2="60" y2="148" class="court-mini-line" />
                </svg>
            </div>
            <div class="court-modal-details">
                <span class="court-type-badge" id="modalCourtType"></span>
                <p class="court-modal-row"><span>Dimensions</span><strong id="modalCourtDim"></strong></p>
                <p class="court-modal-row"><span>Rate</span><strong id="modalCourtPrice"></strong></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="stepInfoClose">Close</button>
                <button type="button" class="btn btn-primary" id="goToBooking">Book This Court</button>
            </div>
        </div>
    </div>

    {{-- Modal 2: calendar (date only — hour picking happens in timePickerModal) --}}
    <div class="modal-overlay" id="courtBookingModal">
        <div class="modal-box modal-box-lg">
            <div class="modal-header">
                <h3 id="modalCourtNameBooking">Book a Court</h3>
                <button type="button" class="modal-close" id="courtBookingModalClose" aria-label="Close">&times;</button>
            </div>

            <div class="booking-flow-progress user-booking-progress" aria-label="Booking progress">
                <span class="booking-flow-step active" data-booking-step="1"><b>1</b>Date &amp; time</span>
                <span class="booking-flow-step" data-booking-step="2"><b>2</b>Equipment</span>
                <span class="booking-flow-step" data-booking-step="3"><b>3</b>Details</span>
                <span class="booking-flow-step" data-booking-step="4"><b>4</b>Payment</span>
            </div>

            <button type="button" class="modal-back" id="backToInfo">&larr; Back to details</button>

            <div class="booking-section">
                <p class="booking-section-label">Pick a date</p>
                <div class="calendar">
                    <div class="calendar-header">
                        <button type="button" class="calendar-nav" id="calPrev" aria-label="Previous month">&lsaquo;</button>
                        <span id="calMonthLabel"></span>
                        <button type="button" class="calendar-nav" id="calNext" aria-label="Next month">&rsaquo;</button>
                    </div>
                    <div class="calendar-weekdays">
                        <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                    </div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToInfo2">Back</button>
            </div>
        </div>
    </div>

    {{-- Modal 2.5: time picker --}}
    <div class="modal-overlay" id="timePickerModal">
        <div class="modal-box modal-box-lg modal-box-timepicker">
            <div class="modal-header-timepicker">
                <button type="button" class="modal-back modal-back-time" id="backToCalendar">&larr; Back to date</button>
                <button type="button" class="modal-close" id="timePickerModalClose" aria-label="Close">&times;</button>
                <div class="booking-flow-progress booking-flow-progress-compact user-booking-progress" aria-label="Booking progress">
                    <span class="booking-flow-step active" data-booking-step="1"><b>1</b>Date &amp; time</span>
                    <span class="booking-flow-step" data-booking-step="2"><b>2</b>Equipment</span>
                    <span class="booking-flow-step" data-booking-step="3"><b>3</b>Details</span>
                    <span class="booking-flow-step" data-booking-step="4"><b>4</b>Payment</span>
                </div>
            </div>

            <div class="time-picker-scroll-area">
                <div class="time-picker-day-switch">
                    <button type="button" class="time-picker-day-nav" id="timePickerPrevDay" aria-label="Previous day">&lsaquo;</button>
                    <h3 id="timePickerDateLabel">Pick Your Hours</h3>
                    <button type="button" class="time-picker-day-nav" id="timePickerNextDay" aria-label="Next day">&rsaquo;</button>
                </div>

                <div class="time-picker-body">
                    <p class="booking-section-label">Select one or more hours</p>

                    <div class="time-picker-legend">
                        <span class="time-picker-legend-item"><span class="time-picker-legend-swatch"></span> Available</span>
                        <span class="time-picker-legend-item"><span class="time-picker-legend-swatch legend-selected"></span> Selected</span>
                        <span class="time-picker-legend-item"><span class="time-picker-legend-swatch legend-booked"></span> Booked</span>
                    </div>

                    <div class="time-slot-list" id="timeSlotGrid"></div>

                    <aside class="booking-selection-summary user-booking-selection" aria-live="polite">
                        <p class="booking-selection-summary-title">Your selection</p>
                        <p><span>Court</span><strong class="selection-court">Choose a court</strong></p>
                        <p><span>Date &amp; time</span><strong class="selection-date-time">Choose a date and time</strong></p>
                        <p><span>Equipment</span><strong class="selection-equipment">None selected</strong></p>
                        <p class="booking-selection-total"><span>Estimated total</span><strong class="selection-total">₱0.00</strong></p>
                    </aside>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToCalendar2">Back</button>
                <button type="button" class="btn btn-primary" id="continueToEquipment">Continue</button>
            </div>
        </div>
    </div>

    {{-- Modal 3: equipment rental (optional) --}}
    <div class="modal-overlay" id="equipmentModal">
        <div class="modal-box modal-box-lg modal-box-scrollable">
            <div class="modal-header equipment-header">
                <h3>Rent Equipment</h3>
                <button type="button" class="modal-close" id="equipmentModalClose" aria-label="Close">&times;</button>
                <div class="booking-flow-progress booking-flow-progress-compact user-booking-progress" aria-label="Booking progress">
                    <span class="booking-flow-step" data-booking-step="1"><b>1</b>Date &amp; time</span>
                    <span class="booking-flow-step active" data-booking-step="2"><b>2</b>Equipment</span>
                    <span class="booking-flow-step" data-booking-step="3"><b>3</b>Details</span>
                    <span class="booking-flow-step" data-booking-step="4"><b>4</b>Payment</span>
                </div>
            </div>

            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">Need a racket or paddle? (optional)</p>
                    <div class="equipment-grid user-equipment-grid" id="equipmentGrid" aria-busy="true">
                        <p class="loading-text">Loading equipment…</p>
                    </div>
                </div>
                <aside class="booking-selection-summary user-booking-selection" aria-live="polite">
                    <p class="booking-selection-summary-title">Your selection</p>
                    <p><span>Court</span><strong class="selection-court">Choose a court</strong></p>
                    <p><span>Date &amp; time</span><strong class="selection-date-time">Choose a date and time</strong></p>
                    <p><span>Equipment</span><strong class="selection-equipment">None selected</strong></p>
                    <p class="booking-selection-total"><span>Estimated total</span><strong class="selection-total">₱0.00</strong></p>
                </aside>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToBookingFromEquipment2">Back Previous</button>
                <button type="button" class="btn btn-primary" id="continueToPayment">Continue</button>
            </div>
        </div>
    </div>

    {{-- Modal 4: contact number + payment method → confirm --}}
    <div class="modal-overlay" id="courtPaymentModal">
        <div class="modal-box modal-box-lg modal-box-scrollable" id="paymentPage">
            <div class="modal-header">
                <h3>Almost Done</h3>
                <button type="button" class="modal-close" id="courtPaymentModalClose" aria-label="Close">&times;</button>
            </div>

            <div class="booking-flow-progress booking-flow-progress-compact user-booking-progress" aria-label="Booking progress">
                <span class="booking-flow-step" data-booking-step="1"><b>1</b>Date &amp; time</span>
                <span class="booking-flow-step" data-booking-step="2"><b>2</b>Equipment</span>
                <span class="booking-flow-step active" data-booking-step="3"><b>3</b>Details</span>
                <span class="booking-flow-step" data-booking-step="4"><b>4</b>Payment</span>
            </div>

            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">Contact Number</p>
                    <div class="guest-info-grid">
                        <div class="guest-info-item">
                            <label for="contactNumber">Contact Number</label>
                            <input type="tel" id="contactNumber" placeholder="09XX XXX XXXX" autocomplete="tel">
                            <p class="book-court-field-error" role="alert">Enter a contact number to continue.</p>
                        </div>
                    </div>
                </div>

                <div class="booking-section">
                    <p class="booking-section-label">Payment Method</p>
                    <div class="payment-grid" id="paymentGrid">
                        <div class="payment-btn payment-btn-qrph selected">
                            <span class="payment-btn-title">QR Ph</span>
                            <span class="payment-btn-sub">Your secure QR code is generated after confirmation. No reference number is needed.</span>
                        </div>
                    </div>
                </div>

                <div class="booking-summary" id="bookingSummary">
                    <p class="booking-summary-title">Final Review</p>
                    <p><span id="summaryDate"></span> &middot; <span id="summaryTime"></span></p>
                    <p id="summaryEquipmentRow" hidden>Equipment: <strong id="summaryEquipment"></strong></p>
                    <p>Payment: <strong id="summaryPayment">QR Ph</strong></p>
                    <p class="booking-summary-total">Total: <strong id="summaryTotal"></strong></p>
                </div>
                <aside class="booking-selection-summary user-booking-selection" aria-live="polite">
                    <p class="booking-selection-summary-title">Your selection</p>
                    <p><span>Court</span><strong class="selection-court">Choose a court</strong></p>
                    <p><span>Date &amp; time</span><strong class="selection-date-time">Choose a date and time</strong></p>
                    <p><span>Equipment</span><strong class="selection-equipment">None selected</strong></p>
                    <p class="booking-selection-total"><span>Estimated total</span><strong class="selection-total">₱0.00</strong></p>
                </aside>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToEquipment2">Back Previous</button>
                <button type="button" class="btn btn-primary" id="confirmBooking">Confirm Booking</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

@endsection

@push('scripts')
    <script src="{{ asset('js/config/book.js') }}" defer></script>
@endpush
