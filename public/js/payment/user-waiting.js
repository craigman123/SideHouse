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
    const exitDialog = document.getElementById('waitingExitDialog');
    const exitTitle = document.getElementById('waitingExitTitle');
    const exitDescription = document.getElementById('waitingExitDescription');
    const exitStayBtn = document.getElementById('waitingExitStay');
    const exitConfirmBtn = document.getElementById('waitingExitConfirm');
    const backToBookingLink = box.querySelector('.wt-back');
    const waitingPageUrl = window.location.href;
    const waitingBackTrapKey = '__sideHouseWaitingPageBackTrap';
    let pollTimer;
    let countdownTimer;
    let resolved = box.dataset.initialStatus !== 'pending';
    let exitAction = null;
    let exitOpener = null;
    let exitBusy = false;
    let historyTrapInFlight = false;
    let previousPageOverflow = null;

    const csrfHeaders = () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        return token ? { 'X-CSRF-TOKEN': token } : {};
    };
    const formatCountdown = (milliseconds) => {
        const seconds = Math.max(0, Math.floor(milliseconds / 1000));
        return `${Math.floor(seconds / 60)}:${(seconds % 60).toString().padStart(2, '0')}`;
    };
    function writeWaitingBackEntry() {
        const currentState = window.history.state;
        const nextState = currentState && typeof currentState === 'object' ? { ...currentState } : {};
        nextState[waitingBackTrapKey] = true;
        window.history.pushState(nextState, '', waitingPageUrl);
    }
    function pushWaitingBackEntry() {
        if (resolved || historyTrapInFlight || window.history.state?.[waitingBackTrapKey]) return;
        historyTrapInFlight = true;
        try {
            writeWaitingBackEntry();
        } finally {
            historyTrapInFlight = false;
        }
    }
    function handleWaitingPopState() {
        if (resolved || historyTrapInFlight) return;
        historyTrapInFlight = true;
        try {
            if (!exitDialog.open && backToBookingLink) openExitDialog(backToBookingLink, false);
            writeWaitingBackEntry();
        } finally {
            historyTrapInFlight = false;
        }
    }
    function installWaitingBackTrap() {
        if (resolved) return;
        window.addEventListener('popstate', handleWaitingPopState);
        pushWaitingBackEntry();
    }
    function removeWaitingBackTrap() {
        window.removeEventListener('popstate', handleWaitingPopState);
    }
    function showResolved(status) {
        removeWaitingBackTrap();
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
    function closeExitDialog(restoreFocus = true) {
        const wasOpen = exitDialog.open;
        const opener = exitOpener;
        exitBusy = false;
        exitAction = null;
        exitDialog.removeAttribute('aria-busy');
        exitConfirmBtn.removeAttribute('aria-busy');
        exitConfirmBtn.disabled = false;
        if (wasOpen) exitDialog.close();
        if (previousPageOverflow) {
            document.documentElement.style.overflow = previousPageOverflow.html;
            document.body.style.overflow = previousPageOverflow.body;
            previousPageOverflow = null;
        }
        exitOpener = null;
        if (restoreFocus && wasOpen && opener?.isConnected && opener.offsetParent !== null) opener.focus({ preventScroll: true });
    }
    function openExitDialog(control, cancel) {
        if (exitBusy || exitDialog.open) return;
        exitOpener = control;
        exitTitle.textContent = cancel ? 'Cancel this booking?' : 'Leave this page?';
        exitDescription.textContent = cancel
            ? 'Your held slot will be released and the QR code stops working.'
            : 'Your slot stays held until the timer runs out. You can return from My Bookings.';
        exitStayBtn.textContent = cancel ? 'Keep waiting' : 'Stay here';
        exitConfirmBtn.textContent = cancel ? 'Yes, cancel booking' : 'Yes, leave';
        exitConfirmBtn.className = `wt-confirm-accept wt-confirm-accept-${cancel ? 'cancel' : 'leave'}`;
        exitAction = cancel ? { type: 'cancel' } : { type: 'back', href: control.href };
        previousPageOverflow = {
            html: document.documentElement.style.overflow,
            body: document.body.style.overflow,
        };
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        exitDialog.showModal();
        exitStayBtn.focus({ preventScroll: true });
    }
    box.addEventListener('click', (event) => {
        const control = event.target.closest('.wt-back, .wt-btn-cancel');
        if (!control || !box.contains(control)) return;
        event.preventDefault();
        openExitDialog(control, control === cancelBtn || control.classList.contains('wt-btn-cancel'));
    });
    exitStayBtn.addEventListener('click', () => closeExitDialog());
    exitConfirmBtn.addEventListener('click', async () => {
        if (exitBusy || !exitAction) return;
        exitBusy = true;
        exitConfirmBtn.disabled = true;
        exitConfirmBtn.setAttribute('aria-busy', 'true');
        exitDialog.setAttribute('aria-busy', 'true');
        if (exitAction.type === 'cancel') {
            await cancelBooking();
            closeExitDialog(!resolved);
        } else {
            removeWaitingBackTrap();
            window.location.href = exitAction.href;
        }
    });
    exitDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!exitBusy) closeExitDialog();
    });
    exitDialog.addEventListener('click', (event) => {
        if (event.target === exitDialog && !exitBusy) closeExitDialog();
    });
    const resolvedDialogObserver = new MutationObserver(() => {
        if (resolved && exitDialog.open && !exitBusy) closeExitDialog(false);
    });
    resolvedDialogObserver.observe(actionsEl, { attributes: true, attributeFilter: ['style'] });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        historyTrapInFlight = false;
        closeExitDialog(false);
        installWaitingBackTrap();
    });
    installWaitingBackTrap();
    // QR generation itself is now handled entirely by qr-cache.js's
    // auto-init (it reads #waitingPage's data-booking-id/data-create-qr-url/
    // data-status-url and wires up #qrLoading/#qrImageWrap/#qrImage/#qrError/
    // #qrRetryBtn/#qrStatusLine on its own). Nothing to do here for that.
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
