@extends('layouts.user')

@section('title', 'Waiting for Payment | Side House')
@section('page-title', 'Waiting for Payment')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    {{-- Not landing.css — its unscoped `body { display: block; }` rule
         loads after app.css and silently breaks app.css's mobile rule
         `body { flex-direction: column; }` (flex-direction is a no-op
         once display isn't flex), which is very likely why the sidebar
         layout wasn't scrolling correctly on narrow viewports. The only
         thing this page actually needed from landing.css was the
         #waitActions rule below. --}}
    <style>#waitActions { justify-content: center; }</style>
@endpush

@push('scripts')
    <script src="{{ asset('js/user-waiting.js') }}" defer></script>
    {{-- Same shared loader guest's waiting page uses — token is optional
         on it (see qr-cache.js's initQRLoader), auth ownership alone is
         enough per PaymongoQrPhController::createQr(). --}}
    <script src="{{ asset('js/qr-cache.js') }}" defer></script>
@endpush

@section('content')

    <div class="panel" style="max-width: 480px; margin: 0 auto;">

        <div
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
            <h2 id="waitTitle">
                @if ($booking->status === 'paid')
                    Payment Confirmed
                @elseif ($booking->status === 'cancelled')
                    Booking Cancelled
                @else
                    Waiting for {{ ucfirst($booking->payment_method ?? 'Payment') }}
                @endif
            </h2>

            <div class="gcash-wait-body">
                <div class="gcash-wait-spinner" id="waitSpinner" aria-hidden="true" @if ($booking->status !== 'pending') style="display:none;" @endif></div>

                @foreach ($siblingBookings as $b)
                    <p class="gcash-wait-amount">
                        <strong>{{ $b->court->name ?? 'Court' }}</strong>
                        — {{ \Carbon\Carbon::parse($b->date)->format('M j, Y') }},
                        {{ \Carbon\Carbon::parse($b->start_time)->format('g:i A') }}–{{ \Carbon\Carbon::parse($b->end_time)->format('g:i A') }}
                        — ₱{{ number_format($b->amount, 2) }}
                    </p>
                @endforeach

                <p class="gcash-wait-amount">Total: <strong>₱{{ number_format($totalAmount, 2) }}</strong></p>

                {{-- QR Ph panel — same classes/behaviour as before, but the
                inner ids now match what qr-cache.js's QRLoader looks for
                (qrLoading / qrImageWrap / qrImage / qrError / qrRetryBtn /
                qrStatusLine), so this reuses the exact same loader guest
                uses instead of a hand-rolled fetch. --}}
                <div
                    class="payment-qr-panel"
                    id="qrphWaitPanel"
                    @if ($booking->payment_method !== 'qrph' || $booking->status !== 'pending') style="display:none;" @endif
                >
                    <p class="payment-qr-instructions">Scan with GCash, Maya, or any QR Ph-enabled banking app. The amount is already filled in — no reference number needed.</p>

                    <div class="payment-qr-image-wrap">
                        <p id="qrLoading">Generating your QR code…</p>

                        <div id="qrImageWrap" hidden>
                            <img id="qrImage" alt="Scan to pay via QR Ph" class="payment-qr-image">
                        </div>

                        <p class="payment-qr-fallback" id="qrError" hidden>
                            Could not generate a QR code.
                            <br>
                            <button type="button" id="qrRetryBtn" class="btn btn-secondary">Try again</button>
                        </p>

                        <p id="qrStatusLine" hidden>Waiting for payment confirmation…</p>
                    </div>
                </div>

                <p class="gcash-wait-status" id="waitStatus">
                    @if ($booking->status === 'paid')
                        Your payment was confirmed — see you on the court!
                    @elseif ($booking->status === 'cancelled')
                        We never received a matching payment in time, so this booking was cancelled and the slot released.
                    @else
                        We'll confirm automatically the moment {{ ucfirst($booking->payment_method ?? 'the payment provider') }} notifies us. It's safe to leave this page and come back from My Bookings later; nothing here cancels this booking except the timer below.
                    @endif
                </p>
                <p class="gcash-wait-countdown" id="waitCountdownRow" @if ($booking->status !== 'pending') style="display:none;" @endif>
                    Slot held for <strong id="waitCountdown">--:--</strong>
                </p>
            </div>

            <div class="modal-actions" id="waitActions" @if ($booking->status !== 'pending') style="display:none;" @endif>
                <button type="button" class="btn btn-secondary" id="waitCancelBtn">Cancel Booking</button>
            </div>

            <div class="modal-actions" id="waitDoneActions" @if ($booking->status === 'pending') style="display:none;" @endif>
                <a href="{{ route('user.bookings.index') }}" class="btn btn-primary">View My Bookings</a>
                <a href="{{ $bookUrl }}" class="btn btn-secondary">Book Another</a>
            </div>
        </div>
    </div>
@endsection