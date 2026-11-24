document.addEventListener('DOMContentLoaded', function () {
    const page = document.getElementById('customersPage');
    if (!page || page.dataset.creditGiver !== 'true') return;

    const openButton = document.getElementById('sendCreditOpen');
    const modal = document.getElementById('sendCreditModal');
    const closeButton = document.getElementById('sendCreditClose');
    const form = document.getElementById('sendCreditForm');
    const formView = document.getElementById('sendCreditFormView');
    const confirmView = document.getElementById('sendCreditConfirmView');
    const emailInput = document.getElementById('sendCreditEmail');
    const amountInput = document.getElementById('sendCreditAmount');
    const lookupMessage = document.getElementById('sendCreditLookup');
    const submitButton = document.getElementById('sendCreditSubmit');
    const cancelButton = document.getElementById('sendCreditCancel');
    const backButton = document.getElementById('sendCreditBack');
    const confirmButton = document.getElementById('sendCreditConfirm');
    const confirmation = document.getElementById('sendCreditConfirmation');
    const previewAmount = document.getElementById('sendCreditPreviewAmount');
    const amountError = document.getElementById('sendCreditAmountError');
    const formError = document.getElementById('sendCreditError');
    const confirmError = document.getElementById('sendCreditConfirmError');
    const idempotencyInput = document.getElementById('sendCreditIdempotency');
    const lookupUrl = page.dataset.creditLookupUrl;
    const storeUrl = page.dataset.creditStoreUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    let recipient = null;
    let lookupTimer = null;
    let lookupRequest = 0;
    let sending = false;
    let previouslyFocused = null;
    const idempotencyStorageKey = 'side-house:send-credit:idempotency';
    const creditMax = Math.max(1, Number.parseInt(page.dataset.creditMax, 10) || 10000);

    function setPreview(text) {
        if (previewAmount) previewAmount.textContent = text;
    }

    function randomToken() {
        if (window.crypto?.getRandomValues) {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        }
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function resetIdempotency() {
        try {
            const saved = sessionStorage.getItem(idempotencyStorageKey);
            if (saved) {
                idempotencyInput.value = saved;
                return;
            }
        } catch (_) {}

        idempotencyInput.value = randomToken();
        try {
            sessionStorage.setItem(idempotencyStorageKey, idempotencyInput.value);
        } catch (_) {}
    }

    function setModalOpen(open) {
        if (open) previouslyFocused = document.activeElement;
        modal.hidden = !open;
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) {
            window.setTimeout(() => emailInput.focus(), 0);
        } else if (previouslyFocused instanceof HTMLElement) {
            previouslyFocused.focus();
        }
    }

    function clearError() {
        formError.hidden = true;
        formError.textContent = '';
        confirmError.hidden = true;
        confirmError.textContent = '';
    }

    function showError(element, message) {
        element.textContent = message;
        element.hidden = false;
    }

    function resetForm() {
        form.reset();
        recipient = null;
        lookupMessage.textContent = '';
        lookupMessage.className = 'send-credit-lookup';
        amountError.textContent = '';
        amountInput.removeAttribute('aria-invalid');
        submitButton.disabled = true;
        previewAmount.textContent = '₱0 Given Credit';
        formView.hidden = false;
        confirmView.hidden = true;
        clearError();
        resetIdempotency();
    }

    function updatePreview() {
        const amount = validateAmount();
        previewAmount.textContent = amount.valid
            ? `₱${amount.value.toLocaleString()} Given Credit`
            : '₱0 Given Credit';
        updateSubmitState();
    }

    function validateAmount() {
        const digits = amountInput.value.replace(/\D/g, '');
        if (amountInput.value !== digits) amountInput.value = digits;

        if (digits === '') {
            amountError.textContent = '';
            amountInput.removeAttribute('aria-invalid');
            return { valid: false, value: 0 };
        }

        const value = Number.parseInt(digits, 10);
        if (!Number.isInteger(value) || value < 1) {
            amountError.textContent = 'Enter at least ₱1.';
            amountInput.setAttribute('aria-invalid', 'true');
            return { valid: false, value: 0 };
        }
        if (value > creditMax) {
            amountError.textContent = `Enter no more than ₱${creditMax.toLocaleString()}.`;
            amountInput.setAttribute('aria-invalid', 'true');
            return { valid: false, value: 0 };
        }

        amountError.textContent = '';
        amountInput.removeAttribute('aria-invalid');
        return { valid: true, value };
    }

    function updateSubmitState() {
        const amount = validateAmount();
        submitButton.disabled = !recipient || !amount.valid;
    }

    async function lookupRecipient() {
        const email = emailInput.value.trim();
        recipient = null;
        updateSubmitState();

        if (!email) {
            lookupMessage.textContent = '';
            lookupMessage.className = 'send-credit-lookup';
            return;
        }

        if (!emailInput.checkValidity()) {
            lookupMessage.textContent = 'Enter a valid email address.';
            lookupMessage.className = 'send-credit-lookup send-credit-lookup-error';
            return;
        }

        const requestId = ++lookupRequest;
        lookupMessage.textContent = 'Looking up account...';
        lookupMessage.className = 'send-credit-lookup';

        try {
            const response = await fetch(`${lookupUrl}?email=${encodeURIComponent(email)}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const data = await response.json();
            if (requestId !== lookupRequest) return;

            if (!response.ok || !data.found) {
                lookupMessage.textContent = data.message || 'No registered account found for that email.';
                lookupMessage.className = 'send-credit-lookup send-credit-lookup-error';
                recipient = null;
            } else {
                recipient = data;
                lookupMessage.textContent = data.name;
                lookupMessage.className = 'send-credit-lookup send-credit-lookup-success';
            }
        } catch (_) {
            if (requestId === lookupRequest) {
                lookupMessage.textContent = 'Could not check this email. Please try again.';
                lookupMessage.className = 'send-credit-lookup send-credit-lookup-error';
            }
        }

        updateSubmitState();
    }

    function openConfirmation() {
        if (!recipient) return;
        clearError();
        const amount = validateAmount();
        if (!amount.valid) {
            showError(formError, `Enter an amount between ₱1 and ₱${creditMax.toLocaleString()}.`);
            return;
        }
        confirmation.textContent = `Send ₱${amount.value.toLocaleString()} Given Credit to ${recipient.name} (${recipient.email})? <br> Given Credit cannot be refunded to cash.`;
        formView.hidden = true;
        confirmView.hidden = false;
        confirmButton.focus();
    }

    async function sendCredit() {
        if (sending || !recipient) return;
        sending = true;
        confirmButton.disabled = true;
        backButton.disabled = true;
        clearError();

        try {
            const response = await fetch(storeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || ''
                },
                body: JSON.stringify({
                    email: emailInput.value.trim(),
                    amount: validateAmount().value,
                    idempotency_token: idempotencyInput.value
                })
            });
            const data = await response.json();
            if (!response.ok) {
                showError(confirmError, data.message || (data.errors ? Object.values(data.errors)[0][0] : 'Could not send Given Credit.'));
                return;
            }

            if (typeof window.showToast === 'function') {
                window.showToast(data.message || 'Given Credit sent.', 'success');
            }
            setModalOpen(false);
            try {
                sessionStorage.removeItem(idempotencyStorageKey);
            } catch (_) {}
            resetForm();
        } catch (_) {
            showError(confirmError, 'Could not send Given Credit. Please try again.');
        } finally {
            sending = false;
            confirmButton.disabled = false;
            backButton.disabled = false;
        }
    }

    openButton?.addEventListener('click', function () {
        resetForm();
        setModalOpen(true);
    });
    closeButton?.addEventListener('click', () => setModalOpen(false));
    cancelButton?.addEventListener('click', () => setModalOpen(false));
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) setModalOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (!modal || modal.hidden) return;
        if (event.key === 'Escape') {
            setModalOpen(false);
            event.preventDefault();
            return;
        }
        if (event.key === 'Tab') {
            const focusable = Array.from(modal.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'
            )).filter((element) => !element.hidden && element.offsetParent !== null);
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                last.focus();
                event.preventDefault();
            } else if (!event.shiftKey && document.activeElement === last) {
                first.focus();
                event.preventDefault();
            }
        }
    });

    emailInput?.addEventListener('input', function () {
        recipient = null;
        updateSubmitState();
        window.clearTimeout(lookupTimer);
        lookupTimer = window.setTimeout(lookupRecipient, 350);
    });
    amountInput?.addEventListener('input', updatePreview);
    form?.addEventListener('submit', function (event) {
        event.preventDefault();
        openConfirmation();
    });
    backButton?.addEventListener('click', function () {
        clearError();
        confirmView.hidden = true;
        formView.hidden = false;
        submitButton.focus();
    });
    confirmButton?.addEventListener('click', sendCredit);

    resetIdempotency();
});
