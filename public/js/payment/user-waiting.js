(function () {
    // Renamed from 'waitingBox' to 'waitingPage' so this shares the same
    // container qr-cache.js's auto-init looks for — no separate QR script
    // needed, we just reuse the shared loader.
    const box = document.getElementById('waitingPage');
    if (!box) return;
    const statusUrl = box.dataset.statusUrl;
    const cancelUrl = box.dataset.cancelUrl;
    const expiresAt = box.dataset.expiresAt ? new Date(box.dataset.expiresAt).getTime() : null;
    const titleEl = document.getElementById('waitTitle');
    const statusEl = document.getElementById('waitStatus');
    const spinnerEl = document.getElementById('waitSpinner');
    const countdownRow = document.getElementById('waitCountdownRow');
    const countdownEl = document.getElementById('waitCountdown');
    const actionsEl = document.getElementById('waitActions');
    const doneActionsEl = document.getElementById('waitDoneActions');
    const cancelBtn = document.getElementById('waitCancelBtn');
    const qrphPanel = document.getElementById('qrphWaitPanel');
    let pollTimer;
    let countdownTimer;
    let resolved = box.dataset.initialStatus !== 'pending';

    const csrfHeaders = () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        return token ? { 'X-CSRF-TOKEN': token } : {};
    };
    const formatCountdown = (milliseconds) => {
        const seconds = Math.max(0, Math.floor(milliseconds / 1000));
        return `${Math.floor(seconds / 60)}:${(seconds % 60).toString().padStart(2, '0')}`;
    };
    function showResolved(status) {
        resolved = true;
        clearTimeout(pollTimer);
        clearInterval(countdownTimer);
        spinnerEl.style.display = 'none';
        countdownRow.style.display = 'none';
        actionsEl.style.display = 'none';
        if (qrphPanel) qrphPanel.style.display = 'none';
        doneActionsEl.style.display = '';
        titleEl.textContent = status === 'paid' ? 'Payment Confirmed' : 'Booking Cancelled';
        statusEl.textContent = status === 'paid'
            ? 'Your QR Ph payment was confirmed — see you on the court!'
            : 'Your payment hold expired, so this booking was cancelled and the slot released.';
    }
    async function poll() {
        if (resolved) return;
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            const data = response.ok ? await response.json() : {};
            if (data.status === 'paid' || data.status === 'cancelled') return showResolved(data.status);
        } catch (error) {
            console.error(error);
        }
        pollTimer = setTimeout(poll, 4000);
    }
    async function cancelBooking() {
        if (resolved) return;
        try {
            const response = await fetch(cancelUrl, { method: 'POST', headers: { Accept: 'application/json', ...csrfHeaders() } });
            if (response.ok) return showResolved('cancelled');
        } catch (error) {
            console.error(error);
        }
        poll();
    }
    // QR generation itself is now handled entirely by qr-cache.js's
    // auto-init (it reads #waitingPage's data-booking-id/data-create-qr-url/
    // data-status-url and wires up #qrLoading/#qrImageWrap/#qrImage/#qrError/
    // #qrRetryBtn/#qrStatusLine on its own). Nothing to do here for that.
    cancelBtn?.addEventListener('click', cancelBooking);
    if (!resolved) {
        if (expiresAt) {
            const updateCountdown = () => {
                const remaining = expiresAt - Date.now();
                countdownEl.textContent = formatCountdown(remaining);
                if (remaining <= 0) {
                    clearInterval(countdownTimer);
                    cancelBooking();
                }
            };
            updateCountdown();
            countdownTimer = setInterval(updateCountdown, 1000);
        }
        poll();
    }
})();