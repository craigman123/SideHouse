<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Side House Paddlers | Guest</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-search.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/skeleton.css') }}">
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
        <div class="landing-nav-wrap">
            <nav class="landing-nav">
                <div class="nav-left">
                    <img src="{{ asset('images/tab_icon.png') }}" alt="Side House" class="nav-logo" loading="lazy">
                    <strong class="nav-title-landing">Side House Paddlers</strong>
                </div>

                <div class="nav-center">
                    <div class="nav-links">
                        <a href="#" class="nav-link active">Home</a>
                        <a href="#bookNow" class="nav-link">Book a Court</a>
                        <a href="#features" class="nav-link">Features</a>
                        <a href="#faq" class="nav-link">FAQ</a>
                        <a href="#findUs" class="nav-link">Find Us</a>
                        <a href="#analytics" class="nav-link">Analytics</a>
                        {{-- <a href="#getMore" class="nav-link">Create Account</a> --}}
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

            {{-- Same destination as the "Get Directions" nav link above,
                 but as its own persistent pill under the nav — stacked in
                 this shared fixed wrapper so it stays on screen and
                 right-aligned under Book Now at every scroll position and
                 screen size, without tracking the nav's height by hand. --}}
            <a
                href="https://www.google.com/maps/dir/?api=1&destination=10.246043101731798,123.78949399013447"
                target="_blank"
                rel="noopener noreferrer"
                class="floating-directions"
                aria-label="Get directions to Side House Paddlers"
            >
                <svg class="floating-directions-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" />
                    <circle cx="12" cy="10" r="2.4" />
                </svg>
                Get Directions
            </a>
        </div>

    <!-- Mobile Menu Overlay -->
    <div class="nav-mobile-overlay" id="navMobileOverlay"></div>

    <!-- Mobile Menu -->
    <div class="nav-mobile-menu" id="navMobileMenu">
        <div class="nav-mobile-panel">
            <div class="nav-links">
                <a href="#" class="nav-link active">Home</a>
                    <a href="#bookNow" class="nav-link">Book a Court</a>
                <a href="#features" class="nav-link">Features</a>
                <a href="#faq" class="nav-link">FAQ</a>
                <a href="#findUs" class="nav-link">Find Us</a>
                <a href="#analytics" class="nav-link">Analytics</a>
                {{-- <a href="#getMore" class="nav-link">Create Account</a> --}}
            </div>

            <div class="nav-search-wrap">
                <div class="nav-search">
                    <svg class="nav-search-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
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

            <div class="hero-visit-details" aria-label="Venue details">
                <span>Open {{ \Carbon\Carbon::createFromTime($openHour)->format('g A') }}–{{ \Carbon\Carbon::createFromTime($closeHour)->format('g A') }}</span>
                <span>423 Tabay, Tunghaan, Minglanilla</span>
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
            {{-- Each entry: {"date": "2026-09-07", "reason": "Reserved for a private event"}.
                 `reason` is null when the admin didn't type one in on the
                 Schedule page, and guest-book.js falls back to a generic
                 "Closed for the day." message in that case. --}}
            data-closure-dates="{{ $closureDates->toJson() }}"
            data-google-client-id="{{ config('services.google.client_id') }}"
            {{-- Peak/night pricing (admin Configuration page) — blank
                 attributes when no peak window is configured, which
                 guest-book.js treats as "peak pricing off". Display-only:
                 the guest widget's running total is just a preview, the
                 server (GuestBookingController::store()/paymentPage())
                 always recomputes and charges the real amount. --}}
            data-peak-start-hour="{{ $peakStartHour }}"
            data-peak-end-hour="{{ $peakEndHour }}"
            data-peak-adjustment-type="{{ $peakAdjustmentType }}"
            data-peak-adjustment-value="{{ $peakAdjustmentValue }}"
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
                    @php
                        $baseRate = $courts->first()->hourly_rate;

                        // Same rule as BusinessSetting::hasPeakPricing() —
                        // a degenerate (zero-length) or unconfigured window
                        // means peak pricing is off.
                        $hasPeakRate = $peakStartHour !== null
                            && $peakEndHour !== null
                            && $peakStartHour !== $peakEndHour
                            && in_array($peakAdjustmentType, ['flat', 'percent'], true)
                            && $peakAdjustmentValue > 0;

                        if ($hasPeakRate) {
                            $peakRate = $peakAdjustmentType === 'percent'
                                ? $baseRate * (1 + $peakAdjustmentValue / 100)
                                : $baseRate + $peakAdjustmentValue;
                        }
                    @endphp

                    @if ($hasPeakRate)
                        <p class="book-now-rate book-now-rate-peak">
                            <span> -- ₱{{ number_format($baseRate, 2) }} / hour average time -- </span>
                            <span class="book-now-rate-peak-note">
                                -- <strong class="book-now-rate-peak-rate"> ₱{{ number_format($peakRate, 2) }} </strong> / hour
                                from {{ \Carbon\Carbon::createFromTime($peakStartHour)->format('g A') }}
                                to {{ \Carbon\Carbon::createFromTime($peakEndHour)->format('g A') }} --
                            </span>
                        </p>
                    @else
                        <p class="book-now-rate">₱{{ number_format($baseRate, 2) }} / hour</p>
                    @endif
                @endif
            </div>

            @if ($courts->isEmpty())
                <p class="empty-state">No courts available right now — check back soon.</p>
            @else
                <div class="book-now-widget">
                    <div class="booking-flow-progress" aria-label="Booking progress">
                        <span class="booking-flow-step active" data-booking-step="1"><b>1</b>Date &amp; time</span>
                        <span class="booking-flow-step" data-booking-step="2"><b>2</b>Equipment</span>
                        <span class="booking-flow-step" data-booking-step="3"><b>3</b>Details</span>
                        <span class="booking-flow-step" data-booking-step="4"><b>4</b>Payment</span>
                    </div>
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
                    <aside class="booking-selection-summary" id="bookingSelectionSummary" aria-live="polite">
                        <p class="booking-selection-summary-title">Your selection</p>
                        <p><span>Court</span><strong id="selectionCourt">{{ $courts->first()->name }}</strong></p>
                        <p><span>Date &amp; time</span><strong id="selectionDateTime">Choose a date and time</strong></p>
                        <p><span>Equipment</span><strong id="selectionEquipment">None selected</strong></p>
                        <p class="booking-selection-total"><span>Estimated total</span><strong id="selectionTotal">₱0.00</strong></p>
                    </aside>
                </div>
            @endif
        </section>

        <p class="description-representation">This section shows the operating schedule for this week and next week. Today's row is outlined, and days already passed are labeled "Past".</p>
        <section class="specific-date-time-closures feature-card weekly-schedule-section">
            @if($weeklySchedule)
                <h3>Weekly Schedule</h3>
                <div class="weekly-schedule-grid">
                    <div class="weekly-schedule-block">
                        <h4 class="weekly-schedule-label">This Week</h4>
                        <div class="schedule-table-wrapper">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Opens at</th>
                                        <th>Closes at</th>
                                        <th>Average</th>
                                        <th>Peak</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($weeklySchedule['thisWeek'] as $day)
                                        <tr class="{{ $day['isToday'] ? 'today-row' : '' }} {{ $day['isPast'] ? 'past-row' : '' }} {{ $day['isFullyClosed'] ? 'closed-row' : '' }}">
                                            <td>
                                                {{ $day['dateLabel'] }}
                                                @if($day['isFullyClosed'])
                                                    <span class="closed-tag">Closed</span>
                                                @endif
                                            </td>
                                            <td>{{ $day['opens_at'] }}</td>
                                            <td class="closure-cell">
                                                <div class="closure-format">
                                                    @if($day['hasEarlyClosure'])
                                                        <span class="closes-usual" title="Usual closing time">{{ $day['usual_closes_at'] }}</span>
                                                        <span class="closes-actual" title="Closing early today">{{ $day['closes_at'] }}</span>
                                                    @else
                                                        {{ $day['closes_at'] }}
                                                    @endif
                                                </div>
                                            </td>
                                            <td>₱{{ number_format($day['average'], 2) }}</td>
                                            <td>
                                                @if($day['peak'])
                                                    ₱{{ number_format($day['peak'], 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="weekly-schedule-block">
                        <h4 class="weekly-schedule-label">Next Week</h4>
                        <div class="schedule-table-wrapper">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Opens at</th>
                                        <th>Closes at</th>
                                        <th>Average</th>
                                        <th>Peak</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($weeklySchedule['nextWeek'] as $day)
                                        <tr class="{{ $day['isToday'] ? 'today-row' : '' }} {{ $day['isPast'] ? 'past-row' : '' }} {{ $day['isFullyClosed'] ? 'closed-row' : '' }}">
                                            <td>
                                                {{ $day['dateLabel'] }}
                                                @if($day['isFullyClosed'])
                                                    <span class="closed-tag">Closed</span>
                                                @endif
                                            </td>
                                            <td>{{ $day['opens_at'] }}</td>
                                            <td class="closure-cell">
                                                <div class="closure-format">
                                                    @if($day['hasEarlyClosure'])
                                                        <span class="closes-usual" title="Usual closing time">{{ $day['usual_closes_at'] }}</span>
                                                        <span class="closes-actual" title="Closing early today">{{ $day['closes_at'] }}</span>
                                                    @else
                                                        {{ $day['closes_at'] }}
                                                    @endif
                                                </div>
                                            </td>
                                            <td>₱{{ number_format($day['average'], 2) }}</td>
                                            <td>
                                                @if($day['peak'])
                                                    ₱{{ number_format($day['peak'], 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        <p class="description-representation"> This section highlights the main features and benefits of Side House Paddlers. Each card briefly explains what users can expect from the booking experience and the facilities provided.</p>
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

            {{-- <div class="feature-card fade-in feature-card-exclusive">
                <div class="feature-icon">🌟</div>
                <h3>Exclusive</h3>
                <p>Create a free account to unlock member-only perks: discounted equipment rentals and booking discounts.</p>
            </div> --}}

            <div class="feature-card fade-in">
                <div class="feature-icon">🚻</div>
                <h3>Comfort Room Available</h3>
                <p>Clean, accessible restrooms on-site so you can freshen up before, during, or after your game.</p>
            </div>

            <div class="feature-card fade-in">
                <div class="feature-icon">1️⃣</div>
                <h3>Single Court</h3>
                <p>Reserve a single court for your game, ensuring you have the space you need with your friends, family, or team. In Side House Paddlers you are welcome!</p>
            </div>

            <div class="feature-card fade-in red-upper-border">
                <div class="feature-icon">🛎️</div>
                <h3>Reserve Special Dates</h3>
                <p>Looking to mark an occasion that means a little more? Reserve the court exclusively for your group — a milestone celebration, a family gathering, or simply a day you'd like uninterrupted with the people who matter most. Our team can help tailor the space and timing to fit.</p>
            </div>
        </section>

        <span id="analytics"></span>
        <section id="courtStats" class="stats-section fade-in" data-stats-url="{{ route('guest.book.monthly-stats') }}">
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
                            <div class="stats-skeleton"><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div></div>
                        </div>
                    </div>
                </div>

                <div class="stats-xaxis-label">Date</div>
            </div>

            <div class="stats-chart-wrap" id="vacantChartWrap">
                <div class="stats-intro" style="margin-bottom: 12px;">
                    <h3 style="margin: 0;">Vacant Slots This Month</h3>
                    <p style="margin: 4px 0 0;">Click any bar to jump straight to booking that date.</p>
                </div>
                <div class="stats-chart-title" id="vacantChartMonthLabel">Loading…</div>

                <div class="stats-chart-frame">
                    <div class="stats-yaxis">
                        <span class="stats-yaxis-title">Vacant Hours</span>
                        <div class="stats-yaxis-numbers" id="vacantYAxisNumbers"></div>
                    </div>

                    <div class="stats-chart-scroll">
                        <div class="stats-chart" id="vacantChart">
                            <div class="stats-skeleton"><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div><div class="stats-skeleton-bar"></div></div>
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
                            <p>We're located at 423 Tabay, Tunghaan, Minglanilla, Cebu — just a short drive from the main highway, with easy access whether you're coming from Cebu City or the south. Tap the button below and Google Maps will guide you straight to our door.</p>
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
                            <p>Yes — we have both padel and pickleball equipment available to rent right when you book, so there's no need to buy your own gear before your first visit. Paddles/rackets and balls are provided on-site, and you're always welcome to bring your own equipment instead if you already have a favorite.</p>
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
                            <p>We accept QRPH, the universal QR payment standard, so you can pay quickly and securely by scanning with any participating bank or e-wallet app — including GCash, Maya, BPI, BDO, and more. No need to worry about which specific app you use; if it supports QR Ph, you're covered.</p>
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
                            <p>To cancel or reschedule a booking, simply call us at <a href="tel:09335191862">0933 519 1863</a> or message us on Facebook Messenger (Side House Paddlers) as early as possible so we can free up the slot for other players. If a refund is due, just let us know which bank or e-wallet you paid with and we'll send it back to the same account.</p>
                        </div>
                    </div>
                </div>
            </div>

            <p class="faq-outro" style="text-align:center; max-width: 560px; margin: 32px auto 0; color: var(--text-muted, #9aa0a6); font-size: 0.95rem; line-height: 1.6;">Still have questions? We're happy to help — but honestly, the fastest way to see what makes Side House Paddlers different is to book a court and experience it yourself.</p>
        </section>

        <section class="member-cta fade-in" id="getMore" style="display: none;">
            <div class="member-cta-inner">
                <h2>Get More From Every Visit</h2>
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

                <a href="https://www.facebook.com/profile.php?id=61594010734013" class="footer-contact">
                    <span class="footer-icon">●</span>
                    <span>Facebook Messenger</span>
                </a>
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
                <div class="equipment-booking-recap" aria-live="polite">
                    <span id="equipmentSelectedTime">No time selected</span>
                    <strong id="equipmentSelectedTotal">₱0.00</strong>
                </div>
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
    <div class="modal-overlay" id="searchModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="searchModalLabel"
         data-request-code-url="{{ route('guest.book.search.request-code') }}"
         data-verify-code-url="{{ route('guest.book.search.verify-code') }}"
         hidden>
        <div class="modal-box modal-box-search" role="document">
            <div class="modal-header">
                <div class="search-modal-header">
                    <h3 id="searchModalLabel">Find Your Booking</h3>
                    <button type="button" class="modal-close" id="searchModalClose" aria-label="Close search">&times;</button>
                </div>
                <p class="search-modal-hint" id="searchModalHint">Enter the email you used when booking — we'll send you a 4-digit code.</p>
            </div>

            <div class="modal-body">
                <form id="bookingSearchEmailForm" class="booking-search-form">
                    <div class="filter-group">
                        <label for="searchEmailInput">Email Address</label>
                        <div class="booking-search-field">
                            <input
                                id="searchEmailInput"
                                class="booking-search-input"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                placeholder="you@example.com"
                                aria-label="Email address"
                            />
                            <button type="button" class="booking-search-field-clear" id="searchEmailClear" aria-label="Clear email" hidden>&times;</button>
                        </div>
                    </div>

                    <button type="submit" class="btn-filter booking-search-submit" id="bookingSearchSendCode">Send Code</button>
                </form>

                <form id="bookingSearchCodeForm" class="booking-search-form" hidden>
                    <div class="filter-group">
                        <label id="searchCodeLabel">4-Digit Code</label>
                        <div class="otp-input-group" id="searchCodeGroup" role="group" aria-labelledby="searchCodeLabel">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-otp-index="0" autocomplete="one-time-code" aria-label="Digit 1 of 4" />
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-otp-index="1" aria-label="Digit 2 of 4" />
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-otp-index="2" aria-label="Digit 3 of 4" />
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-otp-index="3" aria-label="Digit 4 of 4" />
                        </div>
                    </div>
                    
                    <p style="margin:0; color:#6b7280; font-size:13px; line-height:1.6;">
                        <strong style = "color:#0f9700;"> Reason:</strong> Booking privacy is needed to ensure the safety of all participants.
                            And to avoid fraudelent booking attempts.
                    </p>

                    <button type="submit" class="btn-filter booking-search-submit" id="bookingSearchVerifyCode">Verify Code</button>

                    <div class="booking-search-links">
                        <button type="button" class="booking-search-link" id="bookingSearchResendCode">Resend code</button>
                        <button type="button" class="booking-search-link" id="bookingSearchChangeEmail">Use a different email</button>
                    </div>
                </form>

                <div id="searchModalResults" class="booking-search-results" role="listbox" aria-live="polite">
                    <div class="booking-search-results-inner"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="{{ asset('js/guest-book.js') }}" defer></script>
    <script src="{{ asset('js/stats-vacant.js') }}" defer></script>
    <script src="{{ asset('js/landing-search.js') }}" defer></script>
</body>
</html>