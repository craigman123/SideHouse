(function () {

    const box = document.getElementById('paymentPage');
    const courtId = box.dataset.courtId;
    const date = box.dataset.date;
    const slots = JSON.parse(box.dataset.slots || '[]');
    const equipmentPayload = JSON.parse(box.dataset.equipment || '[]');
    const storeUrl = box.dataset.storeUrl;
    const waitingUrlTemplate = box.dataset.waitingUrlTemplate;
    const landingUrl = box.dataset.landingUrl;
    const googleClientId = box.dataset.googleClientId;

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
    let confirmInFlight = false;
    const CHECKOUT_DRAFT_KEY = 'sidehouse_guest_checkout_draft';
    const GUEST_EMAIL_STORAGE_KEY = 'sh_guest_email';

    let prefillInFlight = false;
    const backBtn = document.getElementById('backToEquipment2');

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

    // The code who saves the guest email to localStorage named sh_guest_email
    function saveGuestEmailToDevice(email) {
        if (!email) return;
        try {
            localStorage.setItem(GUEST_EMAIL_STORAGE_KEY, email);
        } catch (err) {
            console.warn('Could not save guest email locally:', err);
        }
    }

    // Prefill guest info from saved email in localStorage if present
    // Shows immediately on load — no network wait, since the email is
    // already known from localStorage.
    function showSavedEmailBadge() {
        let savedEmail;
        try {
            savedEmail = localStorage.getItem(GUEST_EMAIL_STORAGE_KEY);
        } catch (err) {
            return;
        }
        if (!savedEmail) return;

        const savedInput = document.getElementById('guestEmailSaved');
        if (!savedInput) return;
        savedInput.value = savedEmail;
        savedInput.hidden = false;
    }

    // Still async — name/phone genuinely need the server lookup.
    function prefillFromSavedEmail() {
        let savedEmail;
        try {
            savedEmail = localStorage.getItem(GUEST_EMAIL_STORAGE_KEY);
        } catch (err) {
            return;
        }
        if (!savedEmail) return;

        setPrefillLoading(true);

        // Safety net: never leave the form locked if the request hangs
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);

        fetch(`/guest/booking/latest?email=${encodeURIComponent(savedEmail)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data.found) return;
                if (data.name && !guestNameInput.value.trim()) {
                    guestNameInput.value = data.name;
                }
                if (data.phone && !guestContactInput.value.trim()) {
                    guestContactInput.value = data.phone;
                }
            })
            .catch((err) => console.warn('Prefill lookup failed:', err))
            .finally(() => {
                clearTimeout(timeout);
                setPrefillLoading(false);
            });
    }

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
    showSavedEmailBadge();
    prefillFromSavedEmail();

    // ---------- Google sign-in ----------

    function showSignedInState(email) {
        guestEmailLabel.hidden = true;
        googleSignInBtnEl.hidden = true;
        guestEmailConfirmed.hidden = false;
        guestEmailConfirmedAddress.textContent = email;
    }

    function clearGoogleSignIn() {
        googleIdToken = null;
        googleEmail = null;
        guestEmailLabel.hidden = false;
        googleSignInBtnEl.hidden = false;
        guestEmailConfirmed.hidden = true;
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

    function handleGoogleCredential(response) {
        const payload = decodeJwtPayload(response.credential);
        if (!payload || !payload.email) {
            showToast("Couldn't verify that Google account. Please try again.", 'error');
            return;
        }
        googleIdToken = response.credential;
        googleEmail = payload.email;
        showSignedInState(payload.email);
        document.querySelector('.guest-email-block')?.classList.remove('guest-email-block-invalid');
    }

    guestEmailChangeBtn.addEventListener('click', clearGoogleSignIn);

    (function initGoogleSignIn() {
        if (!googleClientId || !googleSignInBtnEl) return;

        function render() {
            let savedEmail = null;
            try {
                savedEmail = localStorage.getItem(GUEST_EMAIL_STORAGE_KEY);
            } catch (err) {
                // ignore — just means no hint
            }

            window.google.accounts.id.initialize({
                client_id: googleClientId,
                callback: handleGoogleCredential,
                auto_select: false,
                ...(savedEmail ? { login_hint: savedEmail } : {}),
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
        if (!googleIdToken) missing.push('an email address (sign in with Google)');
        return missing;
    }

    confirmBtn.addEventListener('click', async () => {
    if (confirmInFlight || prefillInFlight) return;
        const missing = getMissingGuestRequirements();
        if (missing.length > 0) {
            showToast(`Please provide ${formatList(missing)} to continue.`, 'error');
            guestNameInput.classList.toggle('field-invalid', !guestNameInput.value.trim());
            guestContactInput.classList.toggle('field-invalid', !guestContactInput.value.trim());
            document.querySelector('.guest-email-block')?.classList.toggle('guest-email-block-invalid', !googleIdToken);
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
        formData.append('google_id_token', googleIdToken);
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

            saveGuestEmailToDevice(googleEmail);

            if (data.booking && data.booking.status === 'paid') {
                clearCheckoutDraft();
                const params = new URLSearchParams({
                    booking_success: 'Payment already matched — you\'re all set!',
                });
                window.location.href = `${landingUrl}?${params.toString()}`;
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




