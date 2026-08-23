(function () {
    const box = document.getElementById('waitingBox');
    if (!box) return;
    const statusUrl = box.dataset.statusUrl;
    const cancelUrl = box.dataset.cancelUrl;
    const expiresAt = box.dataset.expiresAt ? new Date(box.dataset.expiresAt).getTime() : null;
    const qrphCreateUrl = box.dataset.qrphCreateUrl;
    const bookingId = box.dataset.bookingId;
    const titleEl = document.getElementById('waitTitle');
    const statusEl = document.getElementById('waitStatus');
    const spinnerEl = document.getElementById('waitSpinner');
    const countdownRow = document.getElementById('waitCountdownRow');
    const countdownEl = document.getElementById('waitCountdown');
    const actionsEl = document.getElementById('waitActions');
    const doneActionsEl = document.getElementById('waitDoneActions');
    const cancelBtn = document.getElementById('waitCancelBtn');
    const qrphPanel = document.getElementById('qrphWaitPanel');
    const qrphLoading = document.getElementById('qrphWaitLoading');
    const qrphImage = document.getElementById('qrphWaitImage');
    const qrphError = document.getElementById('qrphWaitError');
    const qrphRetry = document.getElementById('qrphWaitRetry');
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
    async function requestQrPhCode() {
        if (!qrphPanel) return;
        qrphLoading.hidden = false;
        qrphImage.hidden = true;
        qrphError.hidden = true;
        try {
            const response = await fetch(qrphCreateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ booking_id: Number(bookingId) }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Could not generate QR Ph code.');
            qrphImage.src = data.qr_image_url;
            qrphImage.hidden = false;
        } catch (error) {
            console.error(error);
            qrphError.hidden = false;
        } finally {
            qrphLoading.hidden = true;
        }
    }
    qrphRetry?.addEventListener('click', requestQrPhCode);
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
        requestQrPhCode();
        poll();
    }
})();
