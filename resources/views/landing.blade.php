<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Side House Paddlers | Guest</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-search.css') }}">
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

        {{-- Updated navigation with search bar --}}
        <nav class="landing-nav">
            <div class="nav-left">
                <img src="{{ asset('images/tab_icon.png') }}" alt="Side House" class="nav-logo" loading="lazy">
                <strong class="nav-title-landing">Side House Paddlers</strong>
            </div>

            <div class="nav-center">
                <div class="nav-links">
                    <a href="#" class="nav-link active">Home</a>
                    <a href="#bookNow" class="nav-link">Rates</a>
                    <a href="#features" class="nav-link">Features</a>
                    <a href="#faq" class="nav-link">FAQ</a>
                    <a href="#findUs" class="nav-link">Find Us</a>
                    <a href="#getMore" class="nav-link">Create Account</a>
                </div>

                    <button type="button" class="nav-search-show" id="navSearchTrigger" aria-label="Find your booking" aria-haspopup="dialog">
                        <svg class="nav-search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7" />
                            <path d="M16.5 16.5l4.5 4.5" />
                        </svg>
                    </button>
                <a href="#bookNow" class="nav-link nav-link-book">Book Now</a>
            </div>

            <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </nav>

    <!-- Mobile Menu Overlay -->
    <div class="nav-mobile-overlay" id="navMobileOverlay"></div>

    <!-- Mobile Menu -->
    <div class="nav-mobile-menu" id="navMobileMenu">
        <div class="nav-mobile-panel">
            <div class="nav-links">
                <a href="#" class="nav-link active">Home</a>
                <a href="#bookNow" class="nav-link">Rates</a>
                <a href="#features" class="nav-link">Features</a>
                <a href="#faq" class="nav-link">FAQ</a>
                <a href="#findUs" class="nav-link">Find Us</a>
                <a href="#getMore" class="nav-link">Create Account</a>
            </div>

            <div class="nav-search-wrap">
                <div class="nav-search">
                    <svg class="nav-search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7" />
                        <path d="M16.5 16.5l4.5 4.5" />
                    </svg>
                    <input
                        type="text"
                        class="nav-search-input"
                        id="navSearchInputMobile"
                        placeholder="Find your booking..."
                        aria-label="Find your booking"
                        autocomplete="off"
                        readonly
                    >
                    <button type="button" class="nav-search-clear" id="navSearchClearMobile" aria-label="Clear search" hidden>&times;</button>
                </div>
            </div>
        </div>
    </div>

        <section class="hero">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" class="hero-logo">
            <h1>Book Your Court. <span>Play Your Game.</span></h1>
            <p>Reserve a court at Side House in seconds — no account needed. Pick your date, time, and court, and you're set.</p>

            <div class="hero-actions">
                <a href="#bookNow" class="btn-hero-primary">Book Now</a>
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
            data-payment-url="{{ route('guest.book.payment') }}"
            data-store-url="{{ route('guest.book.store') }}"
            data-status-url-template="{{ route('guest.book.status', ['booking' => '__ID__']) }}"
            data-waiting-url-template="{{ route('guest.book.waiting', ['booking' => '__ID__']) }}"
            data-open-hour="{{ $openHour }}"
            data-close-hour="{{ $closeHour }}"
            data-min-duration="{{ $minDuration }}"
            data-max-duration="{{ $maxDuration }}"
            data-step-minutes="{{ $stepMinutes }}"
            data-closed-weekdays="{{ implode(',', $closedWeekdays) }}"
            data-closure-dates="{{ $closureDates->implode(',') }}"
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
                @if ($courts->isNotEmpty())
                    <p class="book-now-rate">₱{{ number_format($courts->first()->hourly_rate, 2) }} / hour</p>
                @endif
            </div>

            @if ($courts->isEmpty())
                <p class="empty-state">No courts available right now — check back soon.</p>
            @else
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

        <section class="features" id="features">
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

            <div class="feature-card fade-in feature-card-exclusive">
                <div class="feature-icon">🌟</div>
                <h3>Exclusive</h3>
                <p>Create a free account to unlock member-only perks: discounted equipment rentals and booking discounts.</p>
            </div>

            <div class="feature-card fade-in">
                <div class="feature-icon">🚻</div>
                <h3>Comfort Room Available</h3>
                <p>Clean, accessible restrooms on-site so you can freshen up before, during, or after your game.</p>
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
                        <span>Is there any equipment that I can rent?</span>
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
                            <p>We currently accept GCash and Landbank payments.</p>
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

        <section class="member-cta fade-in">
            <div class="member-cta-inner">
                <h2 id="getMore">Get More From Every Visit</h2>
                <p>Create a free Side House account to view a detailed history of your bookings, rebook your favorite court and time slot in a single click, and unlock member-only perks like hourly rates discounts and discounted equipment rentals. It only takes a minute.</p>
                <a href="{{ route('register') }}" class="btn-hero-primary">Create Your Account</a>
                <p class="member-cta-sub">Already have an account? <a href="{{ route('login') }}">Log in</a></p>
            </div>
        </section>

        <footer class="landing-footer" id="findUs">
        <div class="footer-inner">

            <!-- Brand -->
            <div class="footer-brand">
                <div class="footer-brand-header">
                    <img
                        src="{{ asset('images/tab_icon.png') }}"
                        alt="Side House Paddlers"
                        class="footer-logo"
                    >
                    <strong>Side House Paddlers</strong>
                </div>

                <p>
                    Book your court, grab your gear, and play your game.
                    Simple, fast, and convenient.
                </p>
            </div>

            <!-- Visit -->
            <div class="footer-column">
                <h4>VISIT</h4>

                <a href="#bookNow">Book a Court</a>
                <a href="#features">Features</a>
                <a href="#faq">FAQ</a>

                <a
                    href="https://www.google.com/maps/dir/?api=1&destination=10.246043101731798,123.78949399013447"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Get Directions
                </a>
            </div>

            <!-- Contact -->
            <div class="footer-column">
                <h4>CONTACT</h4>

                <p class="footer-contact">
                    <span class="footer-icon">📍</span>
                    <span>
                        423 Tabay, Tunghaan<br>
                        Minglanilla, Cebu
                    </span>
                </p>

                <a href="tel:09335191862" class="footer-contact">
                    <span class="footer-icon">☎</span>
                    <span>0933 519 1863</span>
                </a>

                <p class="footer-contact">
                    <span class="footer-icon">●</span>
                    <span>Facebook Messenger</span>
                </p>
            </div>

        </div>

        <div class="footer-bottom">
            <span>
                &copy; {{ date('Y') }} Side House Paddlers.
                All rights reserved.
            </span>

            <span>
                Play. Book. Repeat.
            </span>
        </div>
    </footer>
    </div>

    {{-- Modal 2.5: time picker --}}
    <div class="modal-overlay" id="timePickerModal">
        <div class="modal-box modal-box-lg modal-box-timepicker">
            <div class="modal-header-timepicker">
                <button type="button" class="modal-back modal-back-time" id="backToCalendar">&larr; Back to date</button>
                <button type="button" class="modal-close" id="timePickerModalClose" aria-label="Close">&times;</button>
            </div>

            <div class="time-picker-day-switch">
                <button type="button" class="time-picker-day-nav" id="timePickerPrevDay" aria-label="Previous day">&lsaquo;</button>
                <h3 id="timePickerDateLabel">Pick Your Hours</h3>
                <button type="button" class="time-picker-day-nav" id="timePickerNextDay" aria-label="Next day">&rsaquo;</button>
            </div>

            <div class="time-picker-body">
                <p class="booking-section-label">Select one or more hours</p>

                <div class="time-picker-legend">
                    <span class="time-picker-legend-item">
                        <span class="time-picker-legend-swatch"></span> Available
                    </span>
                    <span class="time-picker-legend-item">
                        <span class="time-picker-legend-swatch legend-selected"></span> Selected
                    </span>
                    <span class="time-picker-legend-item">
                        <span class="time-picker-legend-swatch legend-booked"></span> Booked
                    </span>
                </div>

                <div class="time-slot-list" id="timeSlotGrid"></div>

                <div class="time-picker-fee-panel">
                    <p class="time-picker-fee-ranges" id="timePickerFeeRanges">Select at least one hour</p>
                    <div class="time-picker-fee-total-row">
                        <span>Total</span>
                        <span id="timePickerFeeTotal">₱0</span>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToCalendar2">Back</button>
                <button type="button" class="btn btn-primary" id="continueToEquipment">Continue</button>
            </div>
        </div>
    </div>

    {{-- Modal 3: equipment rental --}}
    <div class="modal-overlay" id="equipmentModal">
        <div class="modal-box modal-box-lg modal-box-scrollable">
            <div class="modal-header equipment-header">
                <h3>Rent Equipment</h3>
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

    <!-- Search modal: insert above your scripts, before the closing </body> -->
    <div class="modal-overlay" id="searchModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="searchModalLabel" data-bookings-search-url="{{ route('guest.book.search') }}" hidden>
        <div class="modal-box modal-box-search" role="document">
            <div class="modal-header">
                <div class="search-modal-header">
                    <h3 id="searchModalLabel">Find Your Booking</h3>
                    <button type="button" class="modal-close" id="searchModalClose" aria-label="Close search">&times;</button>
                </div>
                <p class="search-modal-hint">Look up a booking using the phone number or email you used when booking.</p>
            </div>

            <div class="modal-body">
                <form id="bookingSearchForm" class="booking-search-form">
                    <div class="filter-group">
                        <label for="searchPhoneInput">Phone Number</label>
                        <div class="booking-search-field">
                            <input
                                id="searchPhoneInput"
                                class="booking-search-input"
                                type="text"
                                inputmode="tel"
                                autocomplete="tel"
                                placeholder="09XX XXX XXXX"
                                aria-label="Phone number"
                            />
                            <button type="button" class="booking-search-field-clear" id="searchPhoneClear" aria-label="Clear phone number" hidden>&times;</button>
                        </div>
                    </div>

                    <div class="filter-divider" role="separator" aria-hidden="true">
                        <span class="filter-line"></span>
                        <span class="filter-or">or</span>
                        <span class="filter-line"></span>
                    </div>

                    <div class="filter-group">
                        <label for="searchEmailInput">Email Address</label>
                        <div class="booking-search-field">
                            <input
                                id="searchEmailInput"
                                class="booking-search-input"
                                type="text"
                                inputmode="email"
                                autocomplete="email"
                                placeholder="you@example.com"
                                aria-label="Email address"
                            />
                            <button type="button" class="booking-search-field-clear" id="searchEmailClear" aria-label="Clear email" hidden>&times;</button>
                        </div>
                    </div>

                    <button type="submit" class="btn-filter booking-search-submit" id="bookingSearchSubmit">Find Bookings</button>
                </form>

                <div id="searchModalResults" class="booking-search-results" role="listbox" aria-live="polite">
                    <div class="booking-search-results-inner"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="{{ asset('js/guest-book.js') }}" defer></script>
    <script src="{{ asset('js/landing-search.js') }}" defer></script>
</body>
</html>