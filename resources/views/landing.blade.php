<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Side House Paddlers</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
</head>
<body>

    <div class="landing-wrapper">
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>

        <nav class="landing-nav">
            <img src="{{ asset('images/tab_icon.png') }}" alt="Side House" class="nav-logo">
            <div class="nav-links">
                <a href="#bookNow" class="btn-hero-primary">Book Now</a>
            </div>
        </nav>

        <section class="hero">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" class="hero-logo">
            <h1>Book Your Court. <span>Play Your Game.</span></h1>
            <p>Reserve a court at Side House in seconds — no account needed. Pick your date, time, and court, and you're set.</p>

            <div class="hero-actions">
                <a href="#bookNow" class="btn-hero-primary">Book Now</a>
                <a href="{{ route('register') }}" class="btn-hero-secondary">Create an account</a>
            </div>

            <a href="#bookNow" class="scroll-cue" aria-label="Scroll to booking">
                <span>Scroll to book</span>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </a>
        </section>

        {{-- ---------- Guest booking widget ---------- --}}
        <section
            class="book-now-section fade-in"
            id="bookNow"
            data-availability-url="{{ route('guest.book.availability') }}"
            data-equipment-url="{{ route('guest.book.equipment-availability') }}"
            data-store-url="{{ route('guest.book.store') }}"
            data-open-hour="{{ $openHour }}"
            data-close-hour="{{ $closeHour }}"
            data-min-duration="{{ $minDuration }}"
            data-max-duration="{{ $maxDuration }}"
            data-step-minutes="{{ $stepMinutes }}"
            @if ($courts->isNotEmpty())
                data-court-id="{{ $courts->first()->id }}"
                data-court-name="{{ $courts->first()->name }}"
                data-court-type="{{ $courts->first()->surface_type ?? '—' }}"
                data-court-length="{{ $courts->first()->length }}"
                data-court-width="{{ $courts->first()->width }}"
                data-court-price="{{ $courts->first()->hourly_rate }}"
            @endif
        >
            <div class="book-now-intro">
                <h2>Book a Court Right Now</h2>
                <p>No sign-up required. Pick your date, time, and duration — you're booked.</p>
            </div>

            @if ($courts->isEmpty())
                <p class="empty-state">No courts available right now — check back soon.</p>
            @else
                {{-- Only one court exists, so there's nothing to pick between —
                     straight to the calendar instead of a court-selection step. --}}
                <div class="book-now-widget">
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
                </div>
            @endif
        </section>

        <section class="features">
            <div class="feature-card fade-in">
                <div class="feature-icon">🏓</div>
                <h3>Easy Booking</h3>
                <p>Pick a court, date, and time — submit your booking in under a minute.</p>
            </div>

            <div class="feature-card fade-in">
                <div class="feature-icon">🎒</div>
                <h3>Gear Included</h3>
                <p>Need a racket or paddle? Rent padel and pickleball equipment right when you book.</p>
            </div>

            <div class="feature-card fade-in">
                <div class="feature-icon">⚡</div>
                <h3>Fast & Simple</h3>
                <p>No calls, no messages, no account required — just book online and show up ready to play.</p>
            </div>
        </section>

        <footer class="landing-footer">
            &copy; {{ date('Y') }} Side House Paddlers. All rights reserved.
        </footer>
    </div>

    {{-- Modal 2.5: time + duration picker (opens after picking a date) --}}
    <div class="modal-overlay" id="timePickerModal">
        <div class="modal-box modal-box-lg modal-box-scrollable">
            <div class="modal-header">
                <h3 id="timePickerDateLabel">Pick a Time</h3>
                <button type="button" class="modal-close" id="timePickerModalClose" aria-label="Close">&times;</button>
            </div>

            <button type="button" class="modal-back" id="backToCalendar">&larr; Back to date</button>

            <div class="modal-scroll-body" id="timePickerScrollBody">
                <div class="booking-section" id="timeSection">
                    <p class="booking-section-label">1. Pick a start time</p>
                    <div class="time-slot-grid" id="timeSlotGrid"></div>
                </div>

                <div class="booking-section" id="durationSection" hidden>
                    <p class="booking-section-label">2. How long?</p>
                    <div class="duration-grid" id="durationGrid"></div>
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
            <div class="modal-header">
                <h3>Rent Equipment</h3>
                <button type="button" class="modal-close" id="equipmentModalClose" aria-label="Close">&times;</button>
            </div>

            <button type="button" class="modal-back" id="backToBookingFromEquipment">&larr; Back to date &amp; time</button>

            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">Need a racket or paddle? (optional)</p>
                    <div class="equipment-grid" id="equipmentGrid">
                        <p class="loading-text">Loading equipment…</p>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToBookingFromEquipment2">Back</button>
                <button type="button" class="btn btn-primary" id="continueToGuestInfo">Continue</button>
            </div>
        </div>
    </div>

    {{-- Modal 4: guest details + payment + confirm --}}
    <div class="modal-overlay" id="courtPaymentModal">
        <div class="modal-box modal-box-lg modal-box-scrollable">
            <div class="modal-header">
                <h3>Almost Done</h3>
                <button type="button" class="modal-close" id="courtPaymentModalClose" aria-label="Close">&times;</button>
            </div>

            <button type="button" class="modal-back" id="backToEquipment">&larr; Back to equipment</button>

            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">Who's booking?</p>
                    <div class="guest-info-grid">
                        <div class="guest-info-item">
                            <label for="guestName">Full Name</label>
                            <input type="text" id="guestName" placeholder="Juan Dela Cruz" autocomplete="name">
                        </div>
                        <div class="guest-info-item">
                            <label for="guestContact">Contact Number</label>
                            <input type="tel" id="guestContact" placeholder="09XX XXX XXXX" autocomplete="tel">
                        </div>
                    </div>
                </div>

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
                    <p id="summaryEquipmentRow" hidden>Equipment: <strong id="summaryEquipment"></strong></p>
                    <p>Payment: <strong id="summaryPayment">&mdash;</strong></p>
                    <p class="booking-summary-total">Total: <strong id="summaryTotal"></strong></p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToEquipment2">Back</button>
                <button type="button" class="btn btn-primary" id="confirmBooking">Confirm Booking</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="{{ asset('js/guest-book.js') }}" defer></script>
</body>
</html>