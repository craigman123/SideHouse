<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Confirm &amp; Pay | Side House Paddlers</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/courts.css') }}">
    <script src="{{ asset('js/payment.js') }}" defer></script>
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

        <div
            class="modal-box modal-box-lg modal-box-scrollable"
            id="paymentPage"
            style="position: relative; margin: 40px auto; z-index: 1;"
            data-court-id="{{ $courtId }}"
            data-date="{{ $date }}"
            data-slots='@json($slots)'
            data-equipment='@json($equipmentLines->map(fn ($l) => ["id" => $l["id"], "quantity" => $l["quantity"]])->values())'
            data-store-url="{{ $storeUrl }}"
            data-waiting-url-template="{{ $waitingUrlTemplate }}"
            data-landing-url="{{ $landingUrl }}"
            data-google-client-id="{{ $googleClientId }}"
        >
            <div class="modal-header payment-header">
                <h3>Almost Done</h3>
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
                        <button type="button" class="payment-btn payment-btn-gcash" data-method="gcash">
                            <span class="payment-btn-icon">
                                <img src="{{ asset('images/gcash.png') }}" alt="Gcash logo">
                            </span>
                            <span class="payment-btn-title">GCash</span>
                            <span class="payment-btn-sub">Scan the QR code through Gcash to pay</span>
                        </button>

                        <button type="button" class="payment-btn payment-btn-landbank" data-method="landbank">
                            <span class="payment-btn-icon">
                                <img src="{{ asset('images/lanbak.png') }}" alt="Landbank logo">
                            </span>
                            <span class="payment-btn-title">Landbank</span>
                            <span class="payment-btn-sub">Pay via InstaPay by scanning the QR code</span>
                        </button>
                    </div>

                    <div class="payment-qr-panel" id="gcashQrPanel">
                        <p class="payment-qr-instructions">Scan this code in your GCash app to pay, then enter the reference number from the payment confirmation below.</p>
                        <div class="payment-qr-image-wrap">
                            <img
                                src="{{ asset('images/qr-image.png') }}"
                                alt="GCash QR code"
                                id="gcashQrImage"
                                class="payment-qr-image"
                                onerror="this.hidden=true; document.getElementById('gcashQrFallback').hidden=false;"
                            >
                            <div class="payment-qr-fallback" id="gcashQrFallback" hidden>
                                <span>QR code coming soon</span>
                            </div>
                        </div>

                        <div class="payment-proof-block" id="gcashProofBlock">
                            <label for="gcashRefNumber" class="payment-proof-label">GCash Reference Number</label>
                            <input type="text" id="gcashRefNumber" class="payment-ref-input" placeholder="e.g. 1234 567 890123" autocomplete="off">
                            <p class="payment-proof-note">We confirm automatically the moment GCash notifies us — usually within a minute or two. Your booking stays pending until then.</p>
                        </div>
                    </div>

                    <div class="payment-qr-panel" id="landbankQrPanel">
                        <p class="payment-qr-instructions">Scan this code with your Landbank app (or any InstaPay-enabled app) to pay, then enter the reference number from the transfer confirmation below.</p>
                        <div class="payment-qr-image-wrap">
                            <img
                                src="{{ asset('images/qr-image.png') }}"
                                alt="Landbank InstaPay QR code"
                                id="landbankQrImage"
                                class="payment-qr-image"
                                onerror="this.hidden=true; document.getElementById('landbankQrFallback').hidden=false;"
                            >
                            <div class="payment-qr-fallback" id="landbankQrFallback" hidden>
                                <span>QR code coming soon</span>
                            </div>
                        </div>

                        <div class="payment-proof-block" id="landbankProofBlock">
                            <label for="landbankRefNumber" class="payment-proof-label">Landbank / InstaPay Reference Number</label>
                            <input type="text" id="landbankRefNumber" class="payment-ref-input" placeholder="e.g. 1234 567 890123" autocomplete="off">
                            <p class="payment-proof-note">We confirm automatically the moment the transfer notifies us — usually within a minute or two. Your booking stays pending until then.</p>
                        </div>
                    </div>
                </div>

                <div class="booking-summary" id="bookingSummary">
                    <p class="booking-summary-title">Booking Summary</p>
                    @foreach ($dateSummaries as $line)
                        <p>{{ $line }}</p>
                    @endforeach
                    @if ($equipmentLines->isNotEmpty())
                        <p>Equipment: <strong>{{ $equipmentLines->map(fn ($l) => "{$l['name']} ×{$l['quantity']}")->implode(', ') }}</strong></p>
                    @endif
                    <p>Payment: <strong id="summaryPayment">&mdash;</strong></p>
                    <p class="booking-summary-total">Total: <strong>₱{{ number_format($totalAmount, 2) }}</strong></p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="backToEquipment2">Back</button>
                <button type="button" class="btn btn-primary" id="confirmBooking">Confirm Booking</button>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>
</body>
</html>