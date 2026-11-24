<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Confirm &amp; Pay | Side House Paddlers</title>
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/landing-book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/courts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/payment-info-placeholder.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/guest-payment.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
    <script src="{{ asset('js/guest/payment.js') }}" defer></script>
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="guest-payment-page">
    @include('partials.guest.theme-toggle', ['placement' => 'corner'])
    <div class="landing-wrapper">
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>

        <div class="modal-box modal-box-lg modal-box-scrollable" id="paymentPage" style="margin: auto; z-index: 2;"
            data-court-id="{{ $courtId }}"
            data-date="{{ $date }}"
            data-total-amount="{{ (int) round($totalAmount) }}"
            data-slots='@json($slots)'
            data-equipment='@json($equipmentLines->map(fn ($line) => ["id" => $line["id"], "quantity" => $line["quantity"]])->values())'
            data-store-url="{{ $storeUrl }}"
            data-waiting-url-template="{{ $waitingUrlTemplate }}"
            data-landing-url="{{ $landingUrl }}"
            data-google-client-id="{{ $googleClientId }}"
            data-credit-nonce-url="{{ route('guest.credit.nonce') }}"
            data-credit-verify-url="{{ route('guest.credit.verify') }}"
            data-credit-state-url="{{ route('guest.credit.state') }}"
            data-credit-switch-url="{{ route('guest.credit.switch') }}">
            <div class="modal-header payment-header">
                <h3>Almost Done</h3>
                <a href="{{ $landingUrl }}" class="payment-page-exit" aria-label="Exit booking">&times;</a>
                <div class="booking-flow-progress booking-flow-progress-compact" aria-label="Booking progress">
                    <span class="booking-flow-step complete" data-checkout-step="1"><b>1</b>Date &amp; time</span>
                    <span class="booking-flow-step complete" data-checkout-step="2"><b>2</b>Equipment</span>
                    <span class="booking-flow-step active" data-checkout-step="4"><b>3</b>Details</span>
                    <span class="booking-flow-step" data-checkout-step="3"><b>4</b>Payment</span>
                </div>
            </div>
            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">Who's booking?</p>
                   <div class="guest-info-grid">
                        <div class="guest-info-item">
                            <label for="guestName">Full Name</label>
                            <div class="input-wrap">
                                <input type="text" id="guestName" autocomplete="name">
                                <span id="guestNameLoading" class="field-loading-dots" hidden>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </span>
                            </div>
                        </div>

                        <div class="guest-info-item">
                            <label for="guestContact">Contact Number</label>
                            <div class="input-wrap">
                                <input type="tel" id="guestContact" autocomplete="tel">
                                <span id="guestContactLoading" class="field-loading-dots" hidden>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="guest-email-block">
                        <label class="guest-email-label" id="guestEmailLabel" for="guestEmailSaved" hidden>Email Address</label>
                        <input type="email" id="guestEmailSaved" class="guest-email-saved-input" readonly hidden>
                        <div id="googleSignInBtn" class="google-signin-btn"></div>
                        <div class="guest-email-confirmed" id="guestEmailConfirmed" hidden>
                            <span>Signed in as <strong id="guestEmailConfirmedAddress"></strong></span>
                            <button class="btn btn-link btn-change-email" type="button" id="guestEmailChange">X</button>
                        </div>
                    </div>
                </div>
                <div class="booking-section">
                    <p class="booking-section-label">Payment method</p>
                    <div class="payment-grid" id="paymentGrid">
                        <div class="payment-btn payment-btn-qrph selected"><span class="payment-btn-title">QR Ph</span><span class="payment-btn-sub">Your secure QR code is generated after confirmation. No reference number is needed.</span></div>
                    </div>
                    <div class="guest-credit-payment" id="guestCreditPayment" hidden>
                        <label class="guest-credit-use">
                            <input type="checkbox" id="useGuestCredit" checked>
                            <span>Use my credit</span>
                        </label>
                        <div class="guest-credit-balances" aria-live="polite">
                            <span>Given <strong id="guestGivenBalance">₱0</strong></span>
                            <span>Reserved <strong id="guestReservedBalance">₱0</strong></span>
                            <span>Total <strong id="guestCreditTotal">₱0</strong></span>
                        </div>
                        <div class="guest-credit-payment-summary" aria-live="polite">
                            <p><span>Total</span><strong id="guestCreditCheckoutTotal">₱{{ number_format($totalAmount, 0) }}</strong></p>
                            <p><span>Given used</span><strong id="guestGivenUsed">₱0</strong></p>
                            <p><span>Reserved used</span><strong id="guestReservedUsed">₱0</strong></p>
                            <p><span>Amount to pay by QR</span><strong id="guestCashDue">₱{{ number_format($totalAmount, 0) }}</strong></p>
                        </div>
                    </div>
                </div>
                <div class="booking-summary">
                    <p class="booking-summary-title">Final review</p>
                    @foreach ($dateSummaries as $line)<p>{{ $line }}</p>@endforeach
                    <p>Court rental <strong>PHP {{ number_format($courtAmount, 2) }}</strong></p>
                    @if ($equipmentLines->isNotEmpty())<p>Equipment: <strong>{{ $equipmentLines->map(fn ($line) => "{$line['name']} ×{$line['quantity']}")->implode(', ') }}</strong></p>@endif
                    <p>Payment: <strong id="summaryPayment">QR Ph</strong></p>
                    <p class="booking-summary-total">Total: <strong>₱{{ number_format($totalAmount, 2) }}</strong></p>
                    <p class="payment-confidence-note">Your slot is held for {{ $paymentHoldMinutes }} minutes while you complete the QR Ph payment.</p>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary btn-ghost-cancel" id="backToEquipment2">Back</button>
                <button type="button" class="btn btn-primary" id="confirmBooking">Confirm Booking</button></div>
        </div>
    </div>
    <div id="toastContainer" class="toast-container"></div>
</body>
</html>
