<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Waiting for Payment | Side House Paddlers</title>
    <link rel="stylesheet" href="{{ asset('css/payment.css') }}">
    <link rel="stylesheet" href="{{ asset('css/payment-waiting.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
</head>
<body class="pay-body">

    <div class="pay-bg-glow"></div>
    <div class="pay-particles">
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
        <div class="pay-particle"></div>
    </div>

    <div class="pay-toast-container" id="toastContainer"></div>

    <div
        class="pay-shell"
        id="waitingPage"
        data-booking-id="{{ $booking->id }}"
        data-token="{{ $token }}"
        data-expires-at="{{ optional($booking->expires_at)->toIso8601String() }}"
        data-create-qr-url="{{ route('guest.book.payment.qrph') }}"
        data-status-url="{{ $statusUrl }}"
        data-cancel-url="{{ $cancelUrl }}"
        data-cancel-all-url="{{ $cancelAllUrl }}"
        data-reference-url="{{ $referenceUrl }}"
        data-landing-url="{{ $landingUrl }}"
    >
        <div class="pay-topbar">
            <a href="{{ $landingUrl }}" class="pay-back">&larr; Back to home</a>
        </div>

        <div class="pay-hero">
            <h1>Almost there</h1>
            <p>Scan the QR code below with any GCash, Maya, or InstaPay-enabled bank app to complete payment.</p>
        </div>

        <div class="pay-grid">
            <div class="pay-card pay-form-card">
                <div class="pay-section">
                    <p class="pay-section-label">Scan to Pay — QR Ph</p>

                    <div class="pay-qr-standalone" id="qrBox">
                        <div class="pay-qr-loading" id="qrLoading">Generating your QR code&hellip;</div>

                        <div class="pay-qr-standalone-image-wrap" id="qrImageWrap" hidden>
                            <img id="qrImage" src="" alt="QR Ph payment code">
                        </div>

                        <div class="pay-qr-error" id="qrError" hidden>
                            Couldn't generate your QR code.
                            <br>
                            <button type="button" class="pay-qr-retry" id="qrRetryBtn">Try again</button>
                        </div>

                        <div class="pay-qr-status-line" id="qrStatusLine" hidden>
                            <span class="pay-pulse-dot"></span>
                            <span>Waiting for payment confirmation&hellip;</span>
                        </div>
                    </div>
                </div>

                <div class="pay-section">
                    <p class="pay-section-label">Booking Summary</p>

                    <div class="pay-summary-list">
                        @foreach ($siblingBookings as $sibling)
                            <div class="pay-line">
                                <span>
                                    {{ $sibling->court?->name ?? 'Court' }} &middot;
                                    {{ \Carbon\Carbon::parse($sibling->date)->format('M j, Y') }},
                                    {{ \Carbon\Carbon::parse($sibling->start_time)->format('g:i A') }}&ndash;{{ \Carbon\Carbon::parse($sibling->end_time)->format('g:i A') }}
                                </span>
                                <strong>&#8369;{{ number_format($sibling->amount, 2) }}</strong>
                            </div>
                        @endforeach
                    </div>

                    <hr class="pay-line-divider">

                    <div class="pay-total-row">
                        <span class="pay-total-label">Total</span>
                        <span class="pay-total-amount">&#8369;{{ number_format($totalAmount, 2) }}</span>
                    </div>

                    {{--
                        Optional fallback: lets the guest correct a mistyped
                        reference number if your webhook ever needs a manual
                        nudge. Delete this block (and the matching JS/route)
                        if QR Ph confirmation is fully automatic in your flow.
                    --}}
                    <button type="button" class="pay-ref-toggle" id="refToggle">
                        Payment not going through? Enter reference manually
                    </button>
                    <form class="pay-ref-form" id="refForm">
                        <input type="text" class="pay-input" id="refInput" placeholder="Payment reference number" maxlength="50">
                        <button type="submit">Submit</button>
                    </form>
                </div>

                <div class="pay-cancel-actions">
                    <button type="button" class="pay-btn-cancel" id="cancelBtn">Cancel this booking</button>
                    @if ($siblingBookings->count() > 1)
                        <button type="button" class="pay-btn-cancel-all" id="cancelAllBtn">Cancel all {{ $siblingBookings->count() }} dates</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="pay-float-timer" id="floatTimer">
        <span class="pay-float-timer-label">Slot held for</span>
        <span class="pay-float-timer-value" id="floatTimerValue">--:--</span>
    </div>

    <script src="{{ asset('js/payment-waiting.js') }}" defer></script>
</body>
</html>