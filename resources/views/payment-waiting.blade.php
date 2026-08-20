<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Waiting for Payment | Side House Paddlers</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing-book.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
</head>
<body>

    <div class="landing-wrapper" style="align-items: center; justify-content: center;">

        <div
            class="modal-box"
            id="waitingBox"
            style="position: static; max-width: 420px; width: 100%;"
            data-booking-id="{{ $booking->id }}"
            data-token="{{ $token }}"
            data-status-url="{{ $statusUrl }}"
            data-cancel-url="{{ $cancelUrl }}"
            data-landing-url="{{ $landingUrl }}"
            data-initial-status="{{ $booking->status }}"
            data-expires-at="{{ $booking->expires_at?->toIso8601String() }}"
        >
            <div class="modal-header">
                <h3 id="waitTitle">
                    @if ($booking->status === 'paid')
                        Payment Confirmed
                    @elseif ($booking->status === 'cancelled')
                        Booking Cancelled
                    @else
                        Waiting for {{ ucfirst($booking->payment_method ?? 'Payment') }}
                    @endif
                </h3>
            </div>

            <div class="gcash-wait-body">
                <div class="gcash-wait-spinner" id="waitSpinner" aria-hidden="true" @if ($booking->status !== 'pending') style="display:none;" @endif></div>

                <p class="gcash-wait-amount">
                    <strong>{{ $booking->court->name ?? 'Court' }}</strong>
                    — {{ \Carbon\Carbon::parse($booking->date)->format('M j, Y') }},
                    {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}–{{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                </p>
                <p class="gcash-wait-amount">Amount: <strong>₱{{ number_format($booking->amount, 2) }}</strong></p>
                <p class="gcash-wait-status" id="waitStatus">
                    @if ($booking->status === 'paid')
                        Your payment was confirmed — see you on the court!
                    @elseif ($booking->status === 'cancelled')
                        We never received a matching payment in time, so this booking was cancelled and the slot released.
                    @else
                        We'll confirm automatically the moment {{ ucfirst($booking->payment_method ?? 'the payment provider') }} notifies us — usually within a minute or two. It's safe to close this tab and come back later; nothing here expires this booking except the timer below.
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
                <a href="{{ $landingUrl }}" class="btn btn-primary">Back to Home</a>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const box = document.getElementById('waitingBox');
            const statusUrl = box.dataset.statusUrl + '?token=' + encodeURIComponent(box.dataset.token);
            const cancelUrl = box.dataset.cancelUrl + '?token=' + encodeURIComponent(box.dataset.token);
            const expiresAt = box.dataset.expiresAt ? new Date(box.dataset.expiresAt).getTime() : null;

            const titleEl = document.getElementById('waitTitle');
            const statusEl = document.getElementById('waitStatus');
            const spinnerEl = document.getElementById('waitSpinner');
            const countdownRow = document.getElementById('waitCountdownRow');
            const countdownEl = document.getElementById('waitCountdown');
            const actionsEl = document.getElementById('waitActions');
            const doneActionsEl = document.getElementById('waitDoneActions');
            const cancelBtn = document.getElementById('waitCancelBtn');

            let pollTimer = null;
            let countdownTimer = null;
            let resolved = box.dataset.initialStatus !== 'pending';

            function csrfHeaders() {
                const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
                return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
            }

            function formatCountdown(msRemaining) {
                const totalSeconds = Math.max(0, Math.floor(msRemaining / 1000));
                const mins = Math.floor(totalSeconds / 60);
                const secs = totalSeconds % 60;
                return `${mins}:${secs.toString().padStart(2, '0')}`;
            }

            function showResolved(status) {
                resolved = true;
                if (pollTimer) clearTimeout(pollTimer);
                if (countdownTimer) clearInterval(countdownTimer);
                spinnerEl.style.display = 'none';
                countdownRow.style.display = 'none';
                actionsEl.style.display = 'none';
                doneActionsEl.style.display = '';

                if (status === 'paid') {
                    titleEl.textContent = 'Payment Confirmed';
                    statusEl.textContent = 'Your payment was confirmed — see you on the court!';
                } else {
                    titleEl.textContent = 'Booking Cancelled';
                    statusEl.textContent = "We never received a matching payment in time, so this booking was cancelled and the slot released.";
                }
            }

            async function poll() {
                if (resolved) return;
                try {
                    const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                    if (res.ok) {
                        const data = await res.json();
                        if (data.status === 'paid' || data.status === 'cancelled') {
                            showResolved(data.status);
                            return;
                        }
                    }
                } catch (err) {
                    console.error(err);
                }
                pollTimer = setTimeout(poll, 4000);
            }

            if (!resolved) {
                if (expiresAt) {
                    countdownTimer = setInterval(() => {
                        const remaining = expiresAt - Date.now();
                        countdownEl.textContent = formatCountdown(remaining);
                        if (remaining <= 0) {
                            clearInterval(countdownTimer);
                            countdownTimer = null;
                        }
                    }, 1000);
                    countdownEl.textContent = formatCountdown(expiresAt - Date.now());
                }
                poll();
            }

            cancelBtn?.addEventListener('click', async () => {
                cancelBtn.disabled = true;
                cancelBtn.textContent = 'Cancelling…';
                try {
                    const res = await fetch(cancelUrl, { method: 'POST', headers: { Accept: 'application/json', ...csrfHeaders() } });
                    const data = await res.json().catch(() => ({}));
                    showResolved(data.status === 'paid' ? 'paid' : 'cancelled');
                } catch (err) {
                    console.error(err);
                    cancelBtn.disabled = false;
                    cancelBtn.textContent = 'Cancel Booking';
                }
            });
        })();
    </script>
</body>
</html>
