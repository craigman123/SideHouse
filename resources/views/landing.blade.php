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
    <script src="https://accounts.google.com/gsi/client" async defer></script>
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
            data-status-url-template="{{ route('guest.book.status', ['booking' => '__ID__']) }}"
            data-open-hour="{{ $openHour }}"
            data-close-hour="{{ $closeHour }}"
            data-min-duration="{{ $minDuration }}"
            data-max-duration="{{ $maxDuration }}"
            data-step-minutes="{{ $stepMinutes }}"
            data-google-client-id="{{ config('services.google.client_id') }}"
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

        <section class="stats-section fade-in" id="courtStats" data-stats-url="{{ route('guest.book.monthly-stats') }}">
            <div class="stats-intro">
                <h2>Court Usage This Month</h2>
                <p id="statsMonthLabel">Loading…</p>
            </div>

            <div class="stats-summary">
                <div class="stats-summary-item">
                    <span class="stats-summary-value" id="statsTotalHours">—</span>
                    <span class="stats-summary-label">Hours Booked</span>
                </div>
                <div class="stats-summary-item">
                    <span class="stats-summary-value" id="statsBusiestDay">—</span>
                    <span class="stats-summary-label">Busiest Day</span>
                </div>
                <div class="stats-summary-item">
                    <span class="stats-summary-value" id="statsAvgHours">—</span>
                    <span class="stats-summary-label">Avg Hours / Day</span>
                </div>
            </div>

            <div class="stats-chart-wrap">
                <div class="stats-chart-title" id="statsChartMonthLabel">Loading…</div>

                <div class="stats-chart-frame">
                    <div class="stats-yaxis">
                        <span class="stats-yaxis-title">Number of Bookings</span>
                        <div class="stats-yaxis-numbers" id="statsYAxisNumbers"></div>
                    </div>

                    <div class="stats-chart-scroll">
                        <div class="stats-chart" id="statsChart">
                            <p class="loading-text">Loading chart…</p>
                        </div>
                    </div>
                </div>

                <div class="stats-xaxis-label">Date</div>
            </div>
        </section>

        <section class="faq-section fade-in" id="faq">
            <div class="faq-intro">
                <h2>Frequently Asked Questions</h2>
                <p>Got questions? Here's what you need to know.</p>
            </div>

            <div class="faq-list">
                <div class="faq-item">
                    <button type="button" class="faq-question" aria-expanded="false">
                        <span>Where is the court located?</span>
                        <svg class="faq-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                    <div class="faq-answer-wrap">
                        <div class="faq-answer">
                            <p>We're located in 423 Tabay, Tunghaan Minglanilla Cebu.</p>
                            <a
                                href="https://www.google.com/maps/dir/?api=1&destination=10.246043101731798,123.78949399013447"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="faq-directions-btn"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" />
                                    <circle cx="12" cy="9" r="2.5" />
                                </svg>
                                Directions
                            </a>
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button type="button" class="faq-question" aria-expanded="false">
                        <span>What equipment can we rent?</span>
                        <svg class="faq-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                    <div class="faq-answer-wrap">
                        <div class="faq-answer">
                            <p>We have both padel and pickleball equipment available to rent when you book.</p>
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button type="button" class="faq-question" aria-expanded="false">
                        <span>How do we pay?</span>
                        <svg class="faq-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                    <div class="faq-answer-wrap">
                        <div class="faq-answer">
                            <p>We currently accept GCash.</p>
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <button type="button" class="faq-question" aria-expanded="false">
                        <span>How do we cancel a booking?</span>
                        <svg class="faq-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                    <div class="faq-answer-wrap">
                        <div class="faq-answer">
                            <p>Call us at <a href="tel:09335191862">0933 519 1863</a> or message us on Facebook Messenger (Side House Paddlers). Drop your GCash number so we can send your refund.</p>
                        </div>
                    </div>
                </div>
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

                    {{-- Google vouches for the address instead of the guest
                         typing it twice — the button renders here via
                         google.accounts.id.renderButton() in guest-book.js. --}}
                    <div class="guest-email-block">
                        <label class="guest-email-label" id="guestEmailLabel">Email Address</label>
                        <div id="googleSignInBtn" class="google-signin-btn"></div>
                        <div class="guest-email-confirmed" id="guestEmailConfirmed" hidden>
                            <span class="guest-email-confirmed-icon">&check;</span>
                            <span>Signed in as <strong id="guestEmailConfirmedAddress"></strong></span>
                            <button type="button" class="guest-email-change" id="guestEmailChange">Change</button>
                        </div>
                    </div>
                </div>

                <div class="booking-section">
                    <p class="booking-section-label">How will you pay?</p>
                    <div class="payment-grid" id="paymentGrid">
                        <button type="button" class="payment-btn" data-method="gcash">
                            <span class="payment-btn-title">GCash</span>
                            <span class="payment-btn-sub">Scan  the QR code through Gcash to pay</span>
                        </button>

                        <button type="button" class="payment-btn" data-method="landbank" disabled>
                            <span class="payment-btn-title">Landbank<br><p style="font-size: 10px; color: #8e0a0a">Coming soon</p></span>
                            <span class="payment-btn-sub">Payment using Landbank account by Scanning the Qr Code</span>
                        </button>
                    </div>

                    {{-- Revealed via JS (adds .open) when GCash is selected.
                         Drop the real QR at public/images/gcash-qr.png — no
                         code changes needed. Until that file exists, the
                         image 404s and the fallback box below shows instead. --}}
                    <div class="gcash-qr-panel" id="gcashQrPanel">
                        <p class="gcash-qr-instructions">Scan this code in your GCash app to pay, then enter the reference number from the payment confirmation below.</p>
                        <div class="gcash-qr-image-wrap">
                            <img
                                src="{{ asset('images/gcash-qr.png') }}"
                                alt="GCash QR code"
                                id="gcashQrImage"
                                class="gcash-qr-image"
                                onerror="this.hidden=true; document.getElementById('gcashQrFallback').hidden=false;"
                            >
                            <div class="gcash-qr-fallback" id="gcashQrFallback" hidden>
                                <span>QR code coming soon</span>
                            </div>
                        </div>

                        <div class="gcash-proof-block">
                            <label for="gcashRefNumber" class="gcash-proof-label">GCash Reference Number</label>
                            <input type="text" id="gcashRefNumber" class="gcash-ref-input" placeholder="e.g. 1234 567 890123" autocomplete="off">
                            <p class="gcash-proof-note">We confirm automatically the moment GCash notifies us — usually within a minute or two. Your booking stays pending until then.</p>
                        </div>
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

    <div class="modal-overlay" id="gcashWaitModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>Waiting for GCash Payment</h3>
            </div>
            <div class="gcash-wait-body">
                <div class="gcash-wait-spinner" aria-hidden="true"></div>
                <p class="gcash-wait-amount">Pay <strong id="gcashWaitAmount"></strong> via the QR code you scanned.</p>
                <p class="gcash-wait-status" id="gcashWaitStatus">We'll confirm automatically the moment GCash notifies us — usually within a minute or two.</p>
                <p class="gcash-wait-countdown">Slot held for <strong id="gcashWaitCountdown">--:--</strong></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="gcashWaitCancel">Cancel Booking</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="{{ asset('js/guest-book.js') }}" defer></script>
</body>
</html>