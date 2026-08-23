@extends('layouts.user')

@section('title', 'Waiting for Payment | Side House')
@section('page-title', 'Waiting for Payment')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/book.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    <script src="{{ asset('js/user-waiting.js') }}" defer></script>
@endpush

@section('content')

    <div class="panel" style="max-width: 480px; margin: 0 auto;">

        <div
            id="waitingBox"
            data-booking-id="{{ $booking->id }}"
            data-status-url="{{ $statusUrl }}"
            data-cancel-url="{{ $cancelUrl }}"
            data-update-reference-url="{{ $updateReferenceUrl }}"
            data-book-url="{{ $bookUrl }}"
            data-initial-status="{{ $booking->status }}"
            data-expires-at="{{ $booking->expires_at?->toIso8601String() }}"
            data-payment-method="{{ $booking->payment_method }}"
            data-qrph-create-url="{{ route('guest.book.payment.qrph') }}"
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

                {{-- QR Ph: unlike gcash/maya (static QR shown earlier, on
                the payment-method page), this QR is generated dynamically
                right here, right now, tied to this exact booking_id — see
                user-waiting.js's requestQrPhCode(). Hidden entirely for
                gcash/maya bookings, and hidden once status leaves 'pending'. --}}
                <div
                    class="payment-qr-panel"
                    id="qrphWaitPanel"
                    @if ($booking->payment_method !== 'qrph' || $booking->status !== 'pending') style="display:none;" @endif
                >
                    <p class="payment-qr-instructions">Scan with GCash, Maya, or any QR Ph-enabled banking app. The amount is already filled in — no reference number needed.</p>
                    <div class="payment-qr-image-wrap">
                        <p id="qrphWaitLoading">Generating your QR code…</p>
                        <img id="qrphWaitImage" alt="Scan to pay via QR Ph" class="payment-qr-image" hidden>
                    </div>
                    <p class="payment-qr-fallback" id="qrphWaitError" hidden>Could not generate a QR code. <button type="button" id="qrphWaitRetry" class="btn btn-secondary">Try again</button></p>
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

            <div class="gcash-wait-reference" id="waitReferenceBox" @if ($booking->payment_method === 'qrph' || $booking->status !== 'pending') style="display:none;" @endif>
                <label for="waitReferenceInput">Typo'd your reference number? Fix it here:</label>
                <div class="modal-actions">
                    <input
                        type="text"
                        id="waitReferenceInput"
                        maxlength="50"
                        value="{{ $currentReference }}"
                        placeholder="Payment reference number"
                    >
                    <button type="button" class="btn btn-secondary" id="waitReferenceBtn">Update Reference</button>
                </div>
                <p class="gcash-wait-status" id="waitReferenceMsg"></p>
            </div>

            <div class="modal-actions" id="waitDoneActions" @if ($booking->status === 'pending') style="display:none;" @endif>
                <a href="{{ route('user.bookings.index') }}" class="btn btn-primary">View My Bookings</a>
                <a href="{{ $bookUrl }}" class="btn btn-secondary">Book Another</a>
            </div>
        </div>
    </div>
@endsection