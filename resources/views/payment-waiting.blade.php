<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Waiting for Payment | Side House Paddlers</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/payment-waiting.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
</head>
<body class="wt-body">

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
        data-token="{{ $token }}"
        data-expires-at="{{ optional($booking->expires_at)->toIso8601String() }}"
        data-create-qr-url="{{ route('guest.book.payment.qrph') }}"
        data-status-url="{{ $statusUrl }}"
        data-cancel-url="{{ $cancelUrl }}"
        data-cancel-all-url="{{ $cancelAllUrl }}"
        data-landing-url="{{ $landingUrl }}"
    >
        <a href="{{ $landingUrl }}" class="wt-back">&larr; Back to home</a>

        <div class="wt-hero">
            <h1>Almost there</h1>
            <p>Scan the QR code below with any GCash, Maya, or InstaPay-enabled bank app to complete payment.</p>
        </div>

        <div class="wt-card">
            <div class="wt-section">
                <p class="wt-section-label">Scan to Pay — QR Ph</p>

                <div class="wt-qr-box" id="qrBox">
                    <div class="wt-qr-loading" id="qrLoading">Generating your QR code&hellip;</div>

                    <div class="wt-qr-image-wrap" id="qrImageWrap" hidden>
                        <img id="qrImage" src="" alt="QR Ph payment code">
                    </div>

                    <div class="wt-qr-error" id="qrError" hidden>
                        Couldn't generate your QR code.
                        <br>
                        <button type="button" class="wt-qr-retry" id="qrRetryBtn">Try again</button>
                    </div>

                    <div class="wt-qr-status-line" id="qrStatusLine" hidden>
                        <span class="wt-pulse-dot"></span>
                        <span>Waiting for payment confirmation&hellip;</span>
                    </div>
                </div>
            </div>

            <div class="wt-section">
                <p class="wt-section-label">Booking Summary</p>

                @foreach ($siblingBookings as $sibling)
                    <div class="wt-line">
                        <span>
                            {{ $sibling->court?->name ?? 'Court' }} &middot;
                            {{ \Carbon\Carbon::parse($sibling->date)->format('M j, Y') }},
                            {{ \Carbon\Carbon::parse($sibling->start_time)->format('g:i A') }}&ndash;{{ \Carbon\Carbon::parse($sibling->end_time)->format('g:i A') }}
                        </span>
                        <strong>&#8369;{{ number_format($sibling->amount, 2) }}</strong>
                    </div>
                @endforeach

                <hr class="wt-line-divider">

                <div class="wt-total-row">
                    <span class="wt-total-label">Total</span>
                    <span class="wt-total-amount">&#8369;{{ number_format($totalAmount, 2) }}</span>
                </div>
            </div>

            <div class="wt-section">
                <div class="wt-cancel-actions">
                    <button type="button" class="wt-btn-cancel" id="cancelBtn">Cancel this booking</button>
                </div>
            </div>
        </div>
    </div>

    <div class="wt-float-timer" id="floatTimer">
        <span class="wt-float-timer-label">Slot held for</span>
        <span class="wt-float-timer-value" id="floatTimerValue">--:--</span>
    </div>

    <script src="{{ asset('js/payment-waiting.js') }}" defer></script>
</body>
</html>