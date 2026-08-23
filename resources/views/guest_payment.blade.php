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

        <div class="modal-box modal-box-lg modal-box-scrollable" id="paymentPage" style="margin: auto; z-index: 2;"
            data-court-id="{{ $courtId }}"
            data-date="{{ $date }}"
            data-slots='@json($slots)'
            data-equipment='@json($equipmentLines->map(fn ($line) => ["id" => $line["id"], "quantity" => $line["quantity"]])->values())'
            data-store-url="{{ $storeUrl }}"
            data-waiting-url-template="{{ $waitingUrlTemplate }}"
            data-landing-url="{{ $landingUrl }}"
            data-google-client-id="{{ $googleClientId }}">
            <div class="modal-header payment-header"><h3>Almost Done</h3></div>
            <button type="button" class="modal-back" id="backToEquipment">&larr; Back to equipment</button>
            <div class="modal-scroll-body">
                <div class="booking-section">
                    <p class="booking-section-label">Who's booking?</p>
                    <div class="guest-info-grid">
                        <div class="guest-info-item"><label for="guestName">Full Name</label><input type="text" id="guestName" autocomplete="name"></div>
                        <div class="guest-info-item"><label for="guestContact">Contact Number</label><input type="tel" id="guestContact" autocomplete="tel"></div>
                    </div>
                    <div class="guest-email-block">
                        <label class="guest-email-label" id="guestEmailLabel">Email Address</label>
                        <div id="googleSignInBtn" class="google-signin-btn"></div>
                        <div class="guest-email-confirmed" id="guestEmailConfirmed" hidden><span>Signed in as <strong id="guestEmailConfirmedAddress"></strong></span><button type="button" id="guestEmailChange">Change</button></div>
                    </div>
                </div>
                <div class="booking-section">
                    <p class="booking-section-label">Payment method</p>
                    <div class="payment-grid" id="paymentGrid">
                        <div class="payment-btn payment-btn-qrph selected"><span class="payment-btn-title">QR Ph</span><span class="payment-btn-sub">Your secure QR code is generated after confirmation. No reference number is needed.</span></div>
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
                <button type="button" class="btn btn-secondary" id="backToEquipment2">Back</button>
                <button type="button" class="btn btn-primary" id="confirmBooking">Confirm Booking</button></div>
        </div>
    </div>
    <div id="toastContainer" class="toast-container"></div>
</body>
</html>
