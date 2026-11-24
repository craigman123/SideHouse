(function () {

    const box = document.getElementById('paymentPage');
    const receiptUrlTemplate = box.dataset.receiptUrlTemplate;
    const courtId = box.dataset.courtId;
    const date = box.dataset.date;
    const slots = JSON.parse(box.dataset.slots || '[]');
    const equipmentPayload = JSON.parse(box.dataset.equipment || '[]');
    const storeUrl = box.dataset.storeUrl;
    const waitingUrlTemplate = box.dataset.waitingUrlTemplate;
    const landingUrl = box.dataset.landingUrl;
    const googleClientId = box.dataset.googleClientId;
    const creditNonceUrl = box.dataset.creditNonceUrl;
    const creditVerifyUrl = box.dataset.creditVerifyUrl;
    const creditStateUrl = box.dataset.creditStateUrl;
    const creditSwitchUrl = box.dataset.creditSwitchUrl;

    const guestNameInput = document.getElementById('guestName');
    const guestContactInput = document.getElementById('guestContact');
    const googleSignInBtnEl = document.getElementById('googleSignInBtn');
    const guestEmailConfirmed = document.getElementById('guestEmailConfirmed');
    const guestEmailConfirmedAddress = document.getElementById('guestEmailConfirmedAddress');
    const guestEmailChangeBtn = document.getElementById('guestEmailChange');
    const guestEmailLabel = document.getElementById('guestEmailLabel');
    const paymentGrid = document.getElementById('paymentGrid');
    const summaryPayment = document.getElementById('summaryPayment');
    const confirmBtn = document.getElementById('confirmBooking');
    const checkoutSteps = document.querySelectorAll('[data-checkout-step]');

    const selectedPayment = 'qrph';
    let googleIdToken = null;
    let googleEmail = null;
    let guestIdentityVerified = false;
    let confirmInFlight = false;
    const CHECKOUT_DRAFT_KEY = 'sidehouse_guest_checkout_draft';

    let prefillInFlight = false;
    let guestCredit = null;
    let guestCreditCheckoutKey = null;
    const backBtn = document.getElementById('backToEquipment2');

    const givenIcon = box.dataset.givenIcon;
    const reservedIcon = box.dataset.reservedIcon;
    const googleSkeletonEl = document.getElementById('googleSignInSkeleton');
    const creditBoxEl = document.getElementById('guestCreditPayment');
    const useCreditEl = document.getElementById('useGuestCredit');
    const summaryTotalEl = document.getElementById('summaryTotal');
    const originalSummaryTotal = summaryTotalEl ? summaryTotalEl.textContent : '';
    let signingIn = false;

    const CREDIT_ICONS = {
    given: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 8.5 A6.5 6.5 0 1 0 16.5 15.5"/><path d="M11 12 H18"/><path d="M11 2.5 V5.5"/><path d="M11 18.5 V21.5"/></svg>',
    reserved: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6 L18 12 L12 18 L6 12 Z"/><path d="M3.5 12 H20.5"/><path d="M12 2 V6"/><path d="M12 18 V22"/></svg>',
    };

    function setCredit(el, kind, amount, minus = false) {
        if (!el) return;
        const n = Math.max(0, Number(amount) || 0);
        el.replaceChildren();
        if (minus && n > 0) el.append('-');

        const icon = document.createElement('span');
        icon.className = 'credit-icon';
        icon.innerHTML = CREDIT_ICONS[kind === 'reserved' ? 'reserved' : 'given']; // static markup, no user data
        el.append(icon, n.toLocaleString());

        el.classList.toggle('is-used', minus && n > 0);
    }

    function setSigningIn(on) {
        signingIn = on;

        [guestNameInput, guestContactInput, confirmBtn, backBtn, guestEmailChangeBtn, useCreditEl]
            .forEach((el) => { if (el) el.disabled = on; });

        box.classList.toggle('is-signing-in', on);
        googleSignInBtnEl?.classList.toggle('is-locked', on);
        googleSignInBtnEl?.classList.toggle('is-hidden-while-signing', on);
        if (googleSkeletonEl) googleSkeletonEl.hidden = !on;

        if (creditBoxEl) {
            creditBoxEl.classList.toggle('is-loading', on);
            if (on) creditBoxEl.hidden = false;
            else if (!guestCredit) creditBoxEl.hidden = true;
        }
    }

    document.querySelector('.payment-page-exit')?.addEventListener('click', (e) => {
        if (signingIn) e.preventDefault();
});

    function setConfirmLocked(isLocked) {
        [guestNameInput, guestContactInput, backBtn, guestEmailChangeBtn]
            .forEach((el) => { if (el) el.disabled = isLocked; });

        googleSignInBtnEl?.classList.toggle('is-locked', isLocked);
        box.classList.toggle('is-confirming', isLocked);
    }

    function setPrefillLoading(isLoading) {
        prefillInFlight = isLoading;

        const nameLoading = document.getElementById('guestNameLoading');
        const contactLoading = document.getElementById('guestContactLoading');

        [guestNameInput, guestContactInput, confirmBtn, backBtn, guestEmailChangeBtn]
            .forEach((el) => { if (el) el.disabled = isLoading; });

        // Google's button renders in an iframe, so block it via CSS instead
        googleSignInBtnEl?.classList.toggle('is-locked', isLoading);
        box.classList.toggle('is-prefilling', isLoading);

        if (nameLoading) nameLoading.hidden = !isLoading;
        if (contactLoading) contactLoading.hidden = !isLoading;
    }

    function createCheckoutKey() {
        if (guestCreditCheckoutKey) return guestCreditCheckoutKey;
        if (window.crypto?.getRandomValues) {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            guestCreditCheckoutKey = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        } else {
            guestCreditCheckoutKey = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        }
        return guestCreditCheckoutKey;
    }

    function updateCreditDisplay(credit) {
        if (!credit) return;
        guestCredit = credit;
        if (creditBoxEl) creditBoxEl.hidden = false;
        setCredit(document.getElementById('guestGivenBalance'), 'given', credit.given);
        setCredit(document.getElementById('guestReservedBalance'), 'reserved', credit.reserved);
        setCredit(document.getElementById('guestCreditTotal'), 'given', credit.total);
        updateCreditSummary();
    }

    function updateCreditSummary() {
        if (!guestCredit) return;
        const total = Number(box.dataset.totalAmount || 0);
        const useCredit = useCreditEl?.checked !== false;
        let remaining = useCredit ? total : 0;
        let givenUsed = useCredit ? Math.min(Number(guestCredit.given || 0), remaining) : 0;
        remaining -= givenUsed;
        let reservedUsed = useCredit ? Math.min(Number(guestCredit.reserved || 0), remaining) : 0;
        remaining -= reservedUsed;
        const minimumCash = 1;
        if (remaining > 0 && remaining < minimumCash) {
            const needed = minimumCash - remaining;
            const fromReserved = Math.min(Number(guestCredit.reserved || 0) - reservedUsed, needed);
            reservedUsed += fromReserved;
            remaining -= fromReserved;
            if (remaining > 0) {
                const fromGiven = Math.min(Number(guestCredit.given || 0) - givenUsed, remaining);
                givenUsed += fromGiven;
                remaining -= fromGiven;
            }
        }
        const cashDue = Math.max(0, useCredit ? total - givenUsed - reservedUsed : total);

        setCredit(document.getElementById('guestGivenUsed'), 'given', givenUsed, true);
        setCredit(document.getElementById('guestReservedUsed'), 'reserved', reservedUsed, true);
        setCredit(document.getElementById('guestCreditDiscount'), 'given', givenUsed + reservedUsed, true);
        document.getElementById('guestCashDue').textContent = `₱${cashDue.toLocaleString()}`;

        // Final review total follows what is actually left to pay
        if (summaryTotalEl) {
            summaryTotalEl.textContent = useCredit
                ? `₱${cashDue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                : originalSummaryTotal;
        }
}

    document.getElementById('useGuestCredit')?.addEventListener('change', updateCreditSummary);

    async function restoreVerifiedGuestState() {
        setPrefillLoading(true);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        fetch(creditStateUrl, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data.verified) return;
                if (data.prefill?.name && !guestNameInput.value.trim()) {
                    guestNameInput.value = data.prefill.name;
                }
                if (data.prefill?.phone && !guestContactInput.value.trim()) {
                    guestContactInput.value = data.prefill.phone;
                }
                googleEmail = data.email;
                showSignedInState(data.email);
                if (data.credit) updateCreditDisplay(data.credit);
            })
            .catch((err) => console.warn('Verified guest state failed:', err))
            .finally(() => {
                clearTimeout(timeout);
                setPrefillLoading(false);
            });
    }

    const GUEST_FIELDS_KEY = 'sidehouse_guest_checkout_fields';

    function saveGuestFields() {
        try {
            localStorage.setItem(GUEST_FIELDS_KEY, JSON.stringify({
                name: guestNameInput.value.trim(),
                contact: guestContactInput.value.trim(),
            }));
        } catch (_) {}
    }

    function restoreGuestFields() {
        try {
            const saved = JSON.parse(localStorage.getItem(GUEST_FIELDS_KEY) || 'null');
            if (!saved) return;
            if (saved.name && !guestNameInput.value.trim()) guestNameInput.value = saved.name;
            if (saved.contact && !guestContactInput.value.trim()) guestContactInput.value = saved.contact;
        } catch (_) {}
    }

    function clearGuestFields() {
        try { localStorage.removeItem(GUEST_FIELDS_KEY); } catch (_) {}
    }

    guestNameInput.addEventListener('input', saveGuestFields);
    guestContactInput.addEventListener('input', saveGuestFields);
    restoreGuestFields();

    // Keep only non-sensitive booking choices. This lets the waiting page
    // return an unpaid checkout to the equipment step without relying on
    // bfcache, while never storing the Google token or payment reference.
    function saveCheckoutDraft() {
        try {
            sessionStorage.setItem(CHECKOUT_DRAFT_KEY, JSON.stringify({
                courtId: String(courtId),
                date,
                slots,
                equipment: equipmentPayload,
            }));
        } catch (err) {
            console.warn('Could not save booking draft.', err);
        }
    }

    function clearCheckoutDraft() {
        try {
            sessionStorage.removeItem(CHECKOUT_DRAFT_KEY);
        } catch (err) {
            console.warn('Could not clear booking draft.', err);
        }
    }

    saveCheckoutDraft();

    // ---------- CSRF ----------

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

    // Validates a same-site path before navigating — waitingUrlTemplate
    // comes from our own page dataset (server-rendered), but the final
    // URL is still checked after the booking ID/token are substituted in,
    // same guard used in book.js/guest-book.js/mfa-modal.js/google-signin.js.
    function safeRedirectPath(path, fallback = '/') {
        if (typeof path === 'string' && /^\/(?!\/|\\)/.test(path)) {
            return path;
        }
        return fallback;
    }

    // ---------- Toasts ----------

    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message"></span>
            <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>
        `;
        toast.querySelector('.toast-message').textContent = message;

        toastContainer.appendChild(toast);

        const remove = () => {
            toast.classList.add('toast-out');
            setTimeout(() => toast.remove(), 300);
        };

        toast.querySelector('.toast-close').addEventListener('click', remove);
        setTimeout(remove, 4000);
    }

    // ---------- Payment method selection ----------

    function syncPaymentQrPanels() {
        summaryPayment.textContent = 'QR Ph';
        if (selectedPayment) {
            checkoutSteps.forEach((step) => {
                const stepNumber = Number(step.dataset.checkoutStep);
                step.classList.toggle('active', stepNumber === 4);
                step.classList.toggle('complete', stepNumber < 4);
            });
        }
    }

    syncPaymentQrPanels();
    restoreVerifiedGuestState();

    // ---------- Google sign-in ----------

    function showSignedInState(email) {
        guestIdentityVerified = true;
        guestEmailLabel.hidden = true;
        googleSignInBtnEl.hidden = true;
        guestEmailConfirmed.hidden = false;
        guestEmailConfirmedAddress.textContent = email;
    }

    async function clearGoogleSignIn() {
        googleIdToken = null;
        googleEmail = null;
        guestIdentityVerified = false;
        guestEmailLabel.hidden = false;
        googleSignInBtnEl.hidden = false;
        guestEmailConfirmed.hidden = true;

        // Reset the credit UI so nothing stale is left on screen
        guestCredit = null;
        if (creditBoxEl) creditBoxEl.hidden = true;
        if (summaryTotalEl) summaryTotalEl.textContent = originalSummaryTotal;

        // Signing out means a different person may book, so wipe their details
        guestNameInput.value = '';
        guestContactInput.value = '';
        clearGuestFields();

        try {
            await fetch(creditSwitchUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...csrfHeaders() },
            });
        } catch (_) {}
        if (window.google && window.google.accounts && window.google.accounts.id) {
            window.google.accounts.id.disableAutoSelect();
        }
    }

    function decodeJwtPayload(jwt) {
        try {
            const base64 = jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
            const json = decodeURIComponent(
                atob(base64)
                    .split('')
                    .map((c) => '%' + c.charCodeAt(0).toString(16).padStart(2, '0'))
                    .join('')
            );
            return JSON.parse(json);
        } catch (err) {
            return null;
        }
    }

    let googleNonce = null;

    async function handleGoogleCredential(response) {
    if (signingIn) return;
        setSigningIn(true);
        try {
            const res = await fetch(creditVerifyUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ id_token: response.credential }),
            });
            const data = await res.json();
            if (!res.ok) {
                showToast(data.message || "Couldn't verify that Google account. Please try again.", 'error');
                return;
            }
            if (!data.verified) {
                showToast('Google sign-in is verified, but no registered account or unique credit match was found.', 'success');
                googleIdToken = null;
                googleEmail = null;
                return;
            }
            googleIdToken = response.credential;
            googleEmail = data.email;
            showSignedInState(data.email);
            if (data.prefill?.name && !guestNameInput.value.trim()) guestNameInput.value = data.prefill.name;
            if (data.prefill?.phone && !guestContactInput.value.trim()) guestContactInput.value = data.prefill.phone;
            updateCreditDisplay(data.credit);
            document.querySelector('.guest-email-block')?.classList.remove('guest-email-block-invalid');
        } catch (_) {
            showToast("Couldn't verify that Google account. Please try again.", 'error');
        } finally {
            setSigningIn(false);
        }
    }

    guestEmailChangeBtn.addEventListener('click', clearGoogleSignIn);

    (function initGoogleSignIn() {
        if (!googleClientId || !googleSignInBtnEl) return;

        async function render() {
            if (!googleNonce) {
                try {
                    const nonceResponse = await fetch(creditNonceUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', ...csrfHeaders() },
                    });
                    const nonceData = await nonceResponse.json();
                    googleNonce = nonceData.nonce;
                } catch (_) {
                    googleNonce = null;
                }
            }
            if (!googleNonce) return;

            window.google.accounts.id.initialize({
                client_id: googleClientId,
                callback: handleGoogleCredential,
                auto_select: false,
                nonce: googleNonce,
            });
            window.google.accounts.id.renderButton(googleSignInBtnEl, {
                type: 'standard',
                theme: 'filled_black',
                size: 'large',
                text: 'continue_with',
                shape: 'pill',
            });
        }

        if (window.google && window.google.accounts && window.google.accounts.id) {
            render();
        } else {
            let attempts = 0;
            const wait = setInterval(() => {
                attempts += 1;
                if (window.google && window.google.accounts && window.google.accounts.id) {
                    clearInterval(wait);
                    render();
                } else if (attempts > 40) {
                    clearInterval(wait);
                }
            }, 250);
        }
    })();

    // ---------- Back ----------
    // history.back() rather than a link to the landing page — the
    // guest's date/time/equipment picks live only in that page's
    // JS memory, and the browser's bfcache restores that exact
    // state on a back-navigation as long as nothing here forced a
    // hard reload of it. See GuestBookingController::paymentPage()'s
    // docblock.

    function returnToEquipment() {
        saveCheckoutDraft();
        window.location.href = `${landingUrl}?resume_booking=1`;
    }

    document.getElementById('backToEquipment')?.addEventListener('click', returnToEquipment);
    document.getElementById('backToEquipment2')?.addEventListener('click', returnToEquipment);

    // ---------- Confirm ----------

    function formatList(items) {
        if (items.length === 1) return items[0];
        if (items.length === 2) return `${items[0]} and ${items[1]}`;
        return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
    }

    function getMissingGuestRequirements() {
        const missing = [];
        if (!guestNameInput.value.trim()) missing.push('your name');
        if (!guestContactInput.value.trim()) missing.push('a contact number');
        if (!guestIdentityVerified) missing.push('an email address (sign in with Google)');
        return missing;
    }

    confirmBtn.addEventListener('click', async () => {
    if (confirmInFlight || prefillInFlight) return;
        const missing = getMissingGuestRequirements();
        if (missing.length > 0) {
            showToast(`Please provide ${formatList(missing)} to continue.`, 'error');
            guestNameInput.classList.toggle('field-invalid', !guestNameInput.value.trim());
            guestContactInput.classList.toggle('field-invalid', !guestContactInput.value.trim());
            document.querySelector('.guest-email-block')?.classList.toggle('guest-email-block-invalid', !guestIdentityVerified);
            return;
        }
        document.querySelector('.guest-email-block')?.classList.remove('guest-email-block-invalid');

        confirmInFlight = true;
        setConfirmLocked(true);     
        confirmBtn.disabled = true;
        confirmBtn.setAttribute('aria-busy', 'true');
        const originalLabel = confirmBtn.textContent;
        confirmBtn.textContent = 'Booking…';

        const formData = new FormData();
        formData.append('court_id', courtId);
        formData.append('date', date);
        slots.forEach((key, i) => {
            formData.append(`slots[${i}]`, key);
        });
        formData.append('payment_method', 'qrph');
        formData.append('guest_name', guestNameInput.value.trim());
        formData.append('guest_contact', guestContactInput.value.trim());
        formData.append('use_credit', document.getElementById('useGuestCredit')?.checked ? '1' : '0');
        formData.append('idempotency_token', createCheckoutKey());
        equipmentPayload.forEach((item, i) => {
            formData.append(`equipment[${i}][id]`, item.id);
            formData.append(`equipment[${i}][quantity]`, item.quantity);
        });

        try {
            // No 'Content-Type' header here on purpose — the
            // browser sets multipart/form-data with the correct
            // boundary itself.
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                body: formData,
            });

            const data = await res.json().catch(() => ({}));

            if (res.status === 419) {
                showToast('Your session expired. Taking you back to the booking page…', 'error');
                setTimeout(() => { window.location.href = landingUrl; }, 1200);
                return;
            }

            if (!res.ok) {
                if (res.status === 422 && data.errors) {
                    const firstError = Object.values(data.errors)[0]?.[0];
                    showToast(firstError || 'Please check the highlighted field.', 'error');

                    guestNameInput.classList.toggle('field-invalid', !!data.errors.guest_name);
                    guestContactInput.classList.toggle('field-invalid', !!data.errors.guest_contact);

                    confirmInFlight = false;
                    setConfirmLocked(false); 
                    confirmBtn.disabled = false;
                    confirmBtn.setAttribute('aria-busy', 'false');
                    confirmBtn.textContent = originalLabel;
                    return;
                }

                // Slot/stock conflict — the time picker isn't on
                // this page, so send the guest back to the
                // calendar to pick again rather than trying to
                // patch state here.
                const params = new URLSearchParams({
                    booking_error: data.message || 'That slot is no longer available. Please pick another time.',
                });
                window.location.href = `${landingUrl}?${params.toString()}`;
                return;
            }

            saveGuestFields();

            if (data.status === 'paid') {
                clearCheckoutDraft();
                const receiptUrl = safeRedirectPath(
                    receiptUrlTemplate.replace('__ID__', data.booking_id)
                        + `?token=${encodeURIComponent(data.poll_token)}`,
                    landingUrl
                );
                window.location.replace(receiptUrl);
                return;
            }

            // Full-page redirect to the waiting step — see
            // GuestBookingController::waiting()'s docblock.
            const waitingUrl = safeRedirectPath(
                waitingUrlTemplate.replace('__ID__', data.booking_id)
                    + `?token=${encodeURIComponent(data.poll_token)}`
            );
            window.location.replace(waitingUrl);
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
            confirmInFlight = false;
            setConfirmLocked(false); 
            confirmBtn.disabled = false;
            confirmBtn.setAttribute('aria-busy', 'false');
            confirmBtn.textContent = originalLabel;
        }
    });

    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            confirmInFlight = false;
            setConfirmLocked(false); 
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm Booking';
            confirmBtn.removeAttribute('aria-busy');
            setPrefillLoading(false);
        }
    });
})();




