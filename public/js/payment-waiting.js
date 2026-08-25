(function () {

    const box = document.getElementById('waitingPage');
    const bookingId = box.dataset.bookingId;
    const token = box.dataset.token;
    const expiresAt = box.dataset.expiresAt || null;

    const createQrUrl  = box.dataset.createQrUrl;
    const statusUrl    = box.dataset.statusUrl;
    const cancelUrl    = box.dataset.cancelUrl;
    const cancelAllUrl = box.dataset.cancelAllUrl;
    const landingUrl   = box.dataset.landingUrl;

    // ============================================================
    // BACK NAVIGATION HANDLING
    // ============================================================
    
    let backNavModalShown = false;

    // Push a state to prevent immediate back navigation
    window.history.pushState({ page: 'payment-waiting' }, '', window.location.href);

    // Handle browser back button
    window.addEventListener('popstate', function(e) {
        // If user is trying to go back, show the modal
        if (!backNavModalShown) {
            backNavModalShown = true;
            showBackNavigationModal();
        }
    });

    function showBackNavigationModal() {
        const modal = document.getElementById('wt-back-nav-modal');
        const stayBtn = document.getElementById('wt-back-nav-stay');
        const leaveBtn = document.getElementById('wt-back-nav-leave');

        // Check if booking is still pending before showing modal
        fetch(`${statusUrl}?token=${encodeURIComponent(token)}`, {
            headers: { Accept: 'application/json' },
        })
        .then(res => res.json())
        .then(data => {
            // If booking is already paid or cancelled, just redirect
            if (data.status === 'paid') {
                window.location.href = landingUrl + '?booking_success=Payment confirmed!';
                return;
            }
            if (data.status === 'cancelled') {
                window.location.href = landingUrl + '?booking_error=This booking was cancelled.';
                return;
            }

            // Show the modal
            modal.classList.remove('hidden');
        })
        .catch(() => {
            // If we can't check status, assume booking is still pending
            modal.classList.remove('hidden');
        });

        // Stay on page - close modal and push a new state
        stayBtn.onclick = function() {
            modal.classList.add('hidden');
            backNavModalShown = false;
            // Push a new state so the back button works again
            window.history.pushState({ page: 'payment-waiting' }, '', window.location.href);
        };

        // Leave - cancel booking and go home
        leaveBtn.onclick = function() {
            modal.classList.add('hidden');
            cancelBookingAndLeave(cancelAllUrl, 'Your booking has been cancelled.');
        };
    }

    function cancelBookingAndLeave(url, message) {
        // Show loading state
        const leaveBtn = document.getElementById('wt-back-nav-leave');
        leaveBtn.disabled = true;
        leaveBtn.textContent = 'Cancelling...';

        fetch(`${url}?token=${encodeURIComponent(token)}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                ...csrfHeaders(),
            },
        })
        .then(res => res.json())
        .then(data => {
            // Redirect to home with error message
            const params = new URLSearchParams({ booking_error: message });
            window.location.href = `${landingUrl}?${params.toString()}`;
        })
        .catch(() => {
            // Even if cancel fails, redirect anyway
            const params = new URLSearchParams({ booking_error: message });
            window.location.href = `${landingUrl}?${params.toString()}`;
        });
    }

    // ============================================================
    // CSRF (same as payment.js)
    // ============================================================

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function csrfHeaders() {
        const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (metaToken) return { 'X-CSRF-TOKEN': metaToken };

        const cookieToken = getCookie('XSRF-TOKEN');
        if (cookieToken) return { 'X-XSRF-TOKEN': cookieToken };

        return {};
    }

    // ============================================================
    // Toasts (matches payment-waiting.css's .wt-toast-* classes)
    // ============================================================

    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `wt-toast wt-toast-${type}`;
        toast.innerHTML = `
            <span class="wt-toast-message"></span>
            <button type="button" class="wt-toast-close" aria-label="Dismiss">&times;</button>
        `;
        toast.querySelector('.wt-toast-message').textContent = message;

        toastContainer.appendChild(toast);

        const remove = () => {
            toast.classList.add('wt-toast-out');
            setTimeout(() => toast.remove(), 300);
        };

        toast.querySelector('.wt-toast-close').addEventListener('click', remove);
        setTimeout(remove, 4000);
    }

    // ============================================================
    // Confirm modal (replaces native confirm())
    // ============================================================

    const confirmModalEl        = document.getElementById('wt-confirm-modal');
    const confirmModalMessageEl = document.getElementById('wt-confirm-modal-message');
    const confirmModalCancelBtn = document.getElementById('wt-confirm-modal-cancel');
    const confirmModalConfirmBtn = document.getElementById('wt-confirm-modal-confirm');

    // Resolves true if the user confirms, false if they back out
    // (Cancel button, overlay click, or Escape key).
    function showConfirmModal(message) {
        confirmModalMessageEl.textContent = message;
        confirmModalEl.classList.remove('hidden');

        return new Promise((resolve) => {
            function cleanup(result) {
                confirmModalEl.classList.add('hidden');
                confirmModalConfirmBtn.removeEventListener('click', onConfirm);
                confirmModalCancelBtn.removeEventListener('click', onCancel);
                confirmModalEl.removeEventListener('click', onOverlayClick);
                document.removeEventListener('keydown', onKeydown);
                resolve(result);
            }

            function onConfirm() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onOverlayClick(e) {
                if (e.target === confirmModalEl) cleanup(false);
            }
            function onKeydown(e) {
                if (e.key === 'Escape') cleanup(false);
            }

            confirmModalConfirmBtn.addEventListener('click', onConfirm);
            confirmModalCancelBtn.addEventListener('click', onCancel);
            confirmModalEl.addEventListener('click', onOverlayClick);
            document.addEventListener('keydown', onKeydown);
        });
    }

    // ============================================================
    // QR generation
    // ============================================================

    const qrLoadingEl    = document.getElementById('qrLoading');
    const qrImageWrapEl  = document.getElementById('qrImageWrap');
    const qrImageEl      = document.getElementById('qrImage');
    const qrErrorEl      = document.getElementById('qrError');
    const qrStatusLineEl = document.getElementById('qrStatusLine');
    const qrRetryBtn     = document.getElementById('qrRetryBtn');

    async function loadQr() {
        // Check if QR already loaded
        if (qrImageEl.src && qrImageEl.src !== '') {
            qrLoadingEl.hidden = true;
            qrImageWrapEl.hidden = false;
            qrStatusLineEl.hidden = false;
            return;
        }

        // Show loading state
        qrLoadingEl.hidden = false;
        qrImageWrapEl.hidden = true;
        qrErrorEl.hidden = true;
        qrStatusLineEl.hidden = true;

        try {
            // ✅ SINGLE CALL - get or generate QR
            const res = await fetch(createQrUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ 
                    booking_id: bookingId, 
                    token
                }),
            });

            const data = await res.json().catch(() => ({}));

            // ✅ If QR exists or was generated, show it
            if (data.qr_image_url) {
                qrImageEl.src = data.qr_image_url;
                qrLoadingEl.hidden = true;
                qrImageWrapEl.hidden = false;
                qrStatusLineEl.hidden = false;
                return;
            }

            // ✅ Handle 409 - QR already exists elsewhere
            if (res.status === 409 && data.payment_intent_id) {
                // Reload to get the existing QR
                window.location.reload();
                return;
            }

            // ✅ Show error if something went wrong
            throw new Error(data.message || 'QR generation failed');

        } catch (err) {
            console.error('QR loading error:', err);
            qrLoadingEl.hidden = true;
            qrErrorEl.hidden = false;
        }
    }

    qrRetryBtn.addEventListener('click', loadQr);
    loadQr();

    // ============================================================
    // Status polling
    // ============================================================

    let pollHandle;
    let timerHandle;

    async function pollStatus() {
        try {
            const res = await fetch(`${statusUrl}?token=${encodeURIComponent(token)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;

            const data = await res.json();

            if (data.status === 'paid') {
                clearInterval(pollHandle);
                clearInterval(timerHandle);
                const params = new URLSearchParams({
                    booking_success: "Payment confirmed — you're all set!",
                });
                window.location.href = `${landingUrl}?${params.toString()}`;
            } else if (data.status === 'cancelled') {
                clearInterval(pollHandle);
                clearInterval(timerHandle);
                const params = new URLSearchParams({
                    booking_error: 'This booking was cancelled.',
                });
                window.location.href = `${landingUrl}?${params.toString()}`;
            }
        } catch (err) {
            console.error(err);
        }
    }

    pollHandle = setInterval(pollStatus, 4000);

    // ============================================================
    // Floating countdown
    // ============================================================

    const floatTimerEl      = document.getElementById('floatTimer');
    const floatTimerValueEl = document.getElementById('floatTimerValue');

    function renderCountdown() {
        if (!expiresAt) {
            floatTimerEl.hidden = true;
            return;
        }

        const remainingMs = new Date(expiresAt).getTime() - Date.now();

        if (remainingMs <= 0) {
            floatTimerValueEl.textContent = 'Expired';
            floatTimerEl.classList.add('is-expiring');
            clearInterval(timerHandle);
            return;
        }

        const totalSeconds = Math.floor(remainingMs / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        floatTimerValueEl.textContent = `${minutes}:${String(seconds).padStart(2, '0')}`;

        floatTimerEl.classList.toggle('is-expiring', totalSeconds <= 60);
    }

    renderCountdown();
    timerHandle = setInterval(renderCountdown, 1000);

    // ============================================================
    // Cancel actions
    // ============================================================

    async function cancelBooking(url, confirmMessage) {
        const confirmed = await showConfirmModal(confirmMessage);
        if (!confirmed) return;

        confirmModalConfirmBtn.disabled = true;

        try {
            const res = await fetch(`${url}?token=${encodeURIComponent(token)}`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                showToast(data.message || "Couldn't cancel. Please try again.", 'error');
                return;
            }

            clearInterval(pollHandle);
            clearInterval(timerHandle);
            const params = new URLSearchParams({ booking_error: 'Booking cancelled.' });
            window.location.href = `${landingUrl}?${params.toString()}`;
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
        } finally {
            confirmModalConfirmBtn.disabled = false;
        }
    }

    document.getElementById('cancelBtn').addEventListener('click', () => {
        cancelBooking(cancelAllUrl, 'All dates in this checkout will be released. This can\'t be undone.');
    });
})();