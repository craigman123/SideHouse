<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Waiting for Payment | Side House</title>
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/payment-waiting.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/payment-waiting-modal.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-booking-flow.css') }}">
    <link rel="stylesheet" href="{{ asset('css/shared/page-glow.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
</head>
<body class="wt-body user-booking-waiting-page">
    @include('partials.guest.theme-toggle', ['placement' => 'corner'])

    <div class="wt-toast-container" id="toastContainer"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>

    <div
        class="wt-shell"
        id="waitingPage"
        data-booking-id="{{ $booking->id }}"
        data-status-url="{{ $statusUrl }}"
        data-cancel-url="{{ $cancelUrl }}"
        data-book-url="{{ $bookUrl }}"
        data-initial-status="{{ $booking->status }}"
        data-expires-at="{{ $booking->expires_at?->toIso8601String() }}"
        data-payment-method="{{ $booking->payment_method }}"
        data-create-qr-url="{{ route('guest.book.payment.qrph') }}"
        data-landing-url="{{ route('user.bookings.index') }}"
    >
        <a href="{{ $bookUrl }}?openBooking=1&amp;court_id={{ $booking->court_id }}" class="wt-back btn-ghost-danger" id="backToHome">&larr; Back to booking</a>

        <div class="wt-hero">
            <h1 id="waitTitle">
                @if ($booking->status === 'paid')
                    Payment Confirmed
                @elseif ($booking->status === 'cancelled')
                    Booking Cancelled
                @else
                    Almost there
                @endif
            </h1>
            <p>Scan the QR code below with any GCash, Maya, or InstaPay-enabled bank app to complete payment.</p>
        </div>

        <div class="wt-card panel gcash-wait-body">
            <div class="wt-section">
                <p class="wt-section-label">Scan to Pay — QR Ph</p>
                <p class="payment-qr-instructions">Scan with GCash, Maya, or any QR Ph-enabled banking app. The amount is already filled in.</p>
                <div
                    class="payment-qr-panel"
                    id="qrphWaitPanel"
                    @if ($booking->payment_method !== 'qrph' || $booking->status !== 'pending') style="display:none;" @endif
                >
                    <div class="wt-qr-box" id="qrBox" data-state="loading">
                        <div class="wt-qr-skeleton" id="qrSkeleton" aria-hidden="true"></div>
                        <div class="wt-qr-image-wrap payment-qr-image-wrap" id="qrImageWrap" hidden>
                            <img id="qrImage" alt="Scan to pay via QR Ph" class="payment-qr-image">
                        </div>
                        <div class="wt-qr-error payment-qr-fallback" id="qrError" hidden>
                            QR unavailable, retry
                            <button type="button" id="qrRetryBtn" class="wt-qr-retry btn btn-secondary">Try again</button>
                        </div>
                    </div>
                    <div class="wt-qr-loading" id="qrLoading">Generating your QR code…</div>
                    <div class="wt-qr-status-line" id="qrStatusLine" hidden>
                        <span class="wt-pulse-dot"></span>
                        <span>Waiting for payment confirmation…</span>
                    </div>
                </div>
                <div class="gcash-wait-spinner" id="waitSpinner" aria-hidden="true" hidden></div>
            </div>

            <div class="wt-section">
                <p class="wt-section-label">Booking Summary</p>
                @foreach ($siblingBookings as $b)
                    <div class="wt-line gcash-wait-amount">
                        <span>
                            <strong>{{ $b->court?->name ?? 'Court' }}</strong> &middot;
                            {{ \Carbon\Carbon::parse($b->date)->format('M j, Y') }},
                            {{ \Carbon\Carbon::parse($b->start_time)->format('g:i A') }}&ndash;{{ \Carbon\Carbon::parse($b->end_time)->format('g:i A') }}
                        </span>
                        <strong>&#8369;{{ number_format($b->amount, 2) }}</strong>
                    </div>
                @endforeach
                <hr class="wt-line-divider">
                <div class="wt-total-row">
                    <span class="wt-total-label">Total</span>
                    <span class="wt-total-amount">&#8369;{{ number_format($totalAmount, 2) }}</span>
                </div>
            </div>

            <div class="wt-section">
                <p class="gcash-wait-status wt-booking-status" id="waitStatus">
                    @if ($booking->status === 'paid')
                        Your payment was confirmed — see you on the court!
                    @elseif ($booking->status === 'cancelled')
                        This booking was cancelled and the slot released.
                    @else
                        We'll confirm automatically when the payment provider notifies us.
                    @endif
                </p>
                <div class="wt-float-timer gcash-wait-countdown" id="waitCountdownRow" @if ($booking->status !== 'pending') style="display:none;" @endif>
                    <span class="wt-float-timer-label">Slot held for</span>
                    <strong class="wt-float-timer-value" id="waitCountdown">--:--</strong>
                </div>
                <div class="wt-cancel-actions modal-actions" id="waitActions" @if ($booking->status !== 'pending') style="display:none;" @endif>
                    <button type="button" class="wt-btn-cancel btn btn-secondary btn-ghost-danger" id="waitCancelBtn">Cancel Booking</button>
                </div>
                <div class="wt-cancel-actions modal-actions" id="waitDoneActions" @if ($booking->status === 'pending') style="display:none;" @endif>
                    <a href="{{ route('user.bookings.index') }}" class="btn btn-primary">View My Bookings</a>
                    <a href="{{ $bookUrl }}?openBooking=1&amp;court_id={{ $booking->court_id }}" class="btn btn-secondary">Book Another</a>
                </div>
            </div>
        </div>
    </div>

    <dialog class="wt-confirm-overlay" id="waitingExitDialog" role="alertdialog" aria-modal="true" aria-labelledby="waitingExitTitle" aria-describedby="waitingExitDescription">
        <section class="wt-confirm-card">
            <h2 id="waitingExitTitle">Leave this page?</h2>
            <p id="waitingExitDescription">Your slot stays held until the timer runs out. You can return from My Bookings.</p>
            <div class="wt-confirm-actions">
                <button type="button" class="wt-confirm-stay" id="waitingExitStay">Stay here</button>
                <button type="button" class="wt-confirm-accept wt-confirm-accept-leave" id="waitingExitConfirm">Yes, leave</button>
            </div>
        </section>
    </dialog>

    <script src="{{ asset('js/payment/user-waiting.js') }}" defer></script>
    <script src="{{ asset('js/payment/qr-cache.js') }}" defer></script>
</body>
</html>
