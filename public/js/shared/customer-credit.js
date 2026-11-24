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
    const noteInput = document.getElementById('sendCreditNote');
    const lookupMessage = document.getElementById('sendCreditLookup');
    const submitButton = document.getElementById('sendCreditSubmit');
    const backButton = document.getElementById('sendCreditBack');
    const confirmButton = document.getElementById('sendCreditConfirm');
    const confirmation = document.getElementById('sendCreditConfirmation');
    const previewAmount = document.getElementById('sendCreditPreviewAmount');
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
    const idempotencyStorageKey = 'side-house:send-credit:idempotency';

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
        modal.hidden = !open;
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) {
            window.setTimeout(() => emailInput.focus(), 0);
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
        submitButton.disabled = true;
        previewAmount.textContent = '₱0 Given Credit';
        formView.hidden = false;
        confirmView.hidden = true;
        clearError();
        resetIdempotency();
    }

    function updatePreview() {
        const amount = Math.max(0, Number.parseInt(amountInput.value, 10) || 0);
        previewAmount.textContent = `₱${amount.toLocaleString()} Given Credit`;
        updateSubmitState();
    }

    function updateSubmitState() {
        const amount = Number.parseInt(amountInput.value, 10);
        submitButton.disabled = !recipient || !Number.isInteger(amount) || amount < 1 || amount > 10000;
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
        const amount = Number.parseInt(amountInput.value, 10);
        if (!Number.isInteger(amount) || amount < 1 || amount > 10000) {
            showError(formError, 'Enter an amount between ₱1 and ₱10,000.');
            return;
        }
        confirmation.textContent = `Send ₱${amount.toLocaleString()} Given Credit to ${recipient.name} (${recipient.email})?`;
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
                    amount: Number.parseInt(amountInput.value, 10),
                    note: noteInput.value.trim(),
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
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) setModalOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) setModalOpen(false);
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
