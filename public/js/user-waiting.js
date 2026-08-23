(function () {
    const box = document.getElementById('waitingBox');
    const statusUrl = box.dataset.statusUrl;
    const cancelUrl = box.dataset.cancelUrl;
    const updateReferenceUrl = box.dataset.updateReferenceUrl;
    const expiresAt = box.dataset.expiresAt ? new Date(box.dataset.expiresAt).getTime() : null;
    const paymentMethod = box.dataset.paymentMethod;
    const qrphCreateUrl = box.dataset.qrphCreateUrl;
    const bookingId = box.dataset.bookingId;

    const titleEl = document.getElementById('waitTitle');
    const statusEl = document.getElementById('waitStatus');
    const spinnerEl = document.getElementById('waitSpinner');
    const countdownRow = document.getElementById('waitCountdownRow');
    const countdownEl = document.getElementById('waitCountdown');
    const referenceBox = document.getElementById('waitReferenceBox');
    const referenceInput = document.getElementById('waitReferenceInput');
    const referenceBtn = document.getElementById('waitReferenceBtn');
    const referenceMsg = document.getElementById('waitReferenceMsg');
    const actionsEl = document.getElementById('waitActions');
    const doneActionsEl = document.getElementById('waitDoneActions');
    const cancelBtn = document.getElementById('waitCancelBtn');
    const qrphPanel = document.getElementById('qrphWaitPanel');
    const qrphLoading = document.getElementById('qrphWaitLoading');
    const qrphImage = document.getElementById('qrphWaitImage');
    const qrphError = document.getElementById('qrphWaitError');
    const qrphRetry = document.getElementById('qrphWaitRetry');

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
        referenceBox.style.display = 'none';
        if (qrphPanel) qrphPanel.style.display = 'none';
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

    // Fires the moment the visible countdown reaches 0:00. Without this,
    // the UI just freezes on "0:00" until the next scheduled poll picks up
    // a status the expire-unconfirmed-* cron job hasn't necessarily
    // written yet — leaving a window (up to a full cron interval) where
    // the timer says the hold is over but the page hasn't moved on.
    // Cancelling client-side here doesn't race the cron job: cancelBooking()
    // only touches bookings still in 'pending', so if the webhook or cron
    // already resolved it first, this just gets a 422 we quietly ignore
    // and fall back to poll() to pick up the real status.
    async function handleExpiry() {
        if (resolved) return;
        try {
            const res = await fetch(cancelUrl, { method: 'POST', headers: { Accept: 'application/json', ...csrfHeaders() } });
            if (res.ok) {
                showResolved('cancelled');
                return;
            }
        } catch (err) {
            console.error(err);
        }
        // Cancel didn't go through (already resolved server-side, or a
        // network hiccup) — let the regular poll loop settle on the truth.
        poll();
    }

    async function requestQrPhCode() {
        if (!qrphPanel || paymentMethod !== 'qrph') return;

        qrphLoading.hidden = false;
        qrphImage.hidden = true;
        qrphError.hidden = true;

        try {
            const res = await fetch(qrphCreateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ booking_id: Number(bookingId) }),
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                throw new Error(data.message || 'Could not generate QR.');
            }

            qrphImage.src = data.qr_image_url;
            qrphImage.hidden = false;
            qrphLoading.hidden = true;
        } catch (err) {
            console.error(err);
            qrphLoading.hidden = true;
            qrphError.hidden = false;
        }
    }

    qrphRetry?.addEventListener('click', requestQrPhCode);

    if (!resolved) {
        if (expiresAt) {
            countdownTimer = setInterval(() => {
                const remaining = expiresAt - Date.now();
                countdownEl.textContent = formatCountdown(remaining);
                if (remaining <= 0) {
                    clearInterval(countdownTimer);
                    countdownTimer = null;
                    handleExpiry();
                }
            }, 1000);
            countdownEl.textContent = formatCountdown(expiresAt - Date.now());

            // Covers the case where the page loads (or a background tab
            // becomes active) after expiresAt has already passed — e.g.
            // laptop was asleep — so the very first tick isn't missed.
            if (expiresAt - Date.now() <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                handleExpiry();
            }
        }
        requestQrPhCode();
        poll();
    }

    cancelBtn?.addEventListener('click', async () => {
        cancelBtn.disabled = true;
        cancelBtn.textContent = 'Cancelling…';
        try {
            const res = await fetch(cancelUrl, { method: 'POST', headers: { Accept: 'application/json', ...csrfHeaders() } });
            if (res.ok) {
                showResolved('cancelled');
            } else if (res.status === 419) {
                setTimeout(() => window.location.reload(), 1200);
            } else {
                cancelBtn.disabled = false;
                cancelBtn.textContent = 'Cancel Booking';
            }
        } catch (err) {
            console.error(err);
            cancelBtn.disabled = false;
            cancelBtn.textContent = 'Cancel Booking';
        }
    });

    referenceBtn?.addEventListener('click', async () => {
        const value = referenceInput.value.trim();
        if (!value) {
            referenceMsg.textContent = 'Enter a reference number first.';
            return;
        }

        referenceBtn.disabled = true;
        referenceBtn.textContent = 'Updating…';
        referenceMsg.textContent = '';

        try {
            const res = await fetch(updateReferenceUrl, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ payment_reference: value }),
            });
            if (res.status === 419) {
                // This page is meant to be left open a long time (see the
                // blade's "safe to leave this page" copy), so the CSRF
                // token baked into the <meta> tag at load time can go
                // stale. Reloading grabs a fresh token/session rather than
                // leaving the user stuck resubmitting against a dead one.
                referenceMsg.textContent = 'Your session needs a refresh — reloading the page…';
                setTimeout(() => window.location.reload(), 1200);
                return;
            }

            const data = await res.json().catch(() => ({}));

            if (res.ok && data.status === 'paid') {
                showResolved('paid');
                return;
            }

            // Covers both a plain "updated, still watching" reply
            // and the already_resolved case (booking moved on to
            // paid/cancelled between page load and this click) —
            // either way, re-poll now instead of waiting up to
            // 4s for the next scheduled poll so the UI catches up
            // immediately.
            referenceMsg.textContent = data.message || "Reference number updated — we'll keep watching for a match.";
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            poll();
        } catch (err) {
            console.error(err);
            referenceMsg.textContent = "Couldn't update the reference number — please try again.";
        } finally {
            referenceBtn.disabled = false;
            referenceBtn.textContent = 'Update Reference';
        }
    });
})();