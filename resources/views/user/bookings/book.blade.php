@extends('layouts.user')

@section('title', 'Book a Court | Side House')
@section('page-title', 'Book a Court')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
@endpush

@section('content')

    <div class="panel">
        <h2>Available Courts</h2><span class="count-badge">{{ $courts->count() }} Court(s) Available</span>
        <p class="panel-subtext">Tap a court to see its details, then pick a date and time to book it.</p>

        <div
            class="court-grid"
            id="courtGrid"
            data-availability-url="{{ route('book.availability') }}"
            data-store-url="{{ route('book.store') }}"
            data-open-hour="{{ $openHour }}"
            data-close-hour="{{ $closeHour }}"
            data-min-duration="{{ $minDuration }}"
            data-max-duration="{{ $maxDuration }}"
            data-step-minutes="{{ $stepMinutes }}"
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

    {{-- Modal 2: calendar → time → duration --}}
    <div class="modal-overlay" id="courtBookingModal">
        <div class="modal-box modal-box-lg modal-box-scrollable">
            <div class="modal-header">
                <h3 id="modalCourtNameBooking">Book a Court</h3>
                <button type="button" class="modal-close" id="courtBookingModalClose" aria-label="Close">&times;</button>
            </div>

            <button type="button" class="modal-back" id="backToInfo">&larr; Back to details</button>

            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">1. Pick a date</p>
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

                <div class="booking-section" id="timeSection" hidden>
                    <p class="booking-section-label">2. Pick a start time</p>
                    <div class="time-slot-grid" id="timeSlotGrid"></div>
                </div>

                <div class="booking-section" id="durationSection" hidden>
                    <p class="booking-section-label">3. How long?</p>
                    <div class="duration-grid" id="durationGrid"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToInfo2">Back</button>
                <button type="button" class="btn btn-primary" id="continueToPayment">Continue to Payment</button>
            </div>
        </div>
    </div>

    {{-- Modal 3: payment method → confirm --}}
    <div class="modal-overlay" id="courtPaymentModal">
        <div class="modal-box modal-box-lg modal-box-scrollable">
            <div class="modal-header">
                <h3>Payment</h3>
                <button type="button" class="modal-close" id="courtPaymentModalClose" aria-label="Close">&times;</button>
            </div>

            <button type="button" class="modal-back" id="backToBooking">&larr; Back to date &amp; time</button>

            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">How will you pay?</p>
                    <div class="payment-grid" id="paymentGrid">
                        <button type="button" class="payment-btn" data-method="arrival">
                            <span class="payment-btn-title">Pay on Arrival</span>
                            <span class="payment-btn-sub">Settle cash at the counter</span>
                        </button>
                        <button type="button" class="payment-btn" data-method="ewallet" disabled>
                            <span class="payment-btn-title">E-Wallet</span>
                            <span class="payment-btn-sub">Coming soon</span>
                        </button>
                    </div>
                </div>

                <div class="booking-summary" id="bookingSummary">
                    <p><span id="summaryDate"></span> &middot; <span id="summaryTime"></span></p>
                    <p>Payment: <strong id="summaryPayment">&mdash;</strong></p>
                    <p class="booking-summary-total">Total: <strong id="summaryTotal"></strong></p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToBooking2">Back</button>
                <button type="button" class="btn btn-primary" id="confirmBooking">Confirm Booking</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

@endsection

@push('scripts')
    <script src="{{ asset('js/book.js') }}" defer></script>
@endpush