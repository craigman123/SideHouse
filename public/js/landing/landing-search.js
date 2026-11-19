/**
 * Landing page search functionality
 * Handles the mobile nav toggle, active-link highlighting on scroll, and
 * the "Find Your Booking" modal (phone/email lookup).
 */

document.addEventListener('DOMContentLoaded', function() {

    // ----- Mobile Navigation Toggle (Smooth) -----
    const navToggle = document.getElementById('navToggle');
    const mobileOverlay = document.getElementById('navMobileOverlay');
    const mobileMenu = document.getElementById('navMobileMenu');

    function openMobileMenu() {
        navToggle.classList.add('active');
        mobileOverlay.classList.add('open');
        mobileOverlay.style.display = 'block';
        mobileMenu.classList.add('open');
        document.body.style.overflow = 'hidden';
        navToggle.setAttribute('aria-expanded', 'true');
    }

    function closeMobileMenu() {
        navToggle.classList.remove('active');
        mobileOverlay.classList.remove('open');
        mobileOverlay.style.display = 'none';
        mobileMenu.classList.remove('open');
        document.body.style.overflow = '';
        navToggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMobileMenu() {
        if (mobileMenu.classList.contains('open')) {
            closeMobileMenu();
        } else {
            openMobileMenu();
        }
    }

    if (navToggle && mobileOverlay && mobileMenu) {
        navToggle.addEventListener('click', toggleMobileMenu);
        mobileOverlay.addEventListener('click', closeMobileMenu);

        // Close mobile nav when clicking a link
        const mobileLinks = mobileMenu.querySelectorAll('.nav-link');
        mobileLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                closeMobileMenu();
            });
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mobileMenu && mobileMenu.classList.contains('open')) {
            closeMobileMenu();
        }
    });

    // ----- Active link highlighting based on scroll -----
    const sections = ['home', 'bookNow', 'features', 'faq', 'getMore', 'findUs'];
    const allNavLinks = document.querySelectorAll('.nav-link');

    function updateActiveLink() {
        const scrollPos = window.scrollY + 120;
        let activeSection = 'home';

        sections.forEach(function(id) {
            let el;
            if (id === 'home') {
                el = document.querySelector('.hero');
            } else {
                el = document.getElementById(id);
            }
            if (el) {
                const top = el.offsetTop;
                const height = el.offsetHeight;
                if (scrollPos >= top && scrollPos < top + height) {
                    activeSection = id;
                }
            }
        });

        allNavLinks.forEach(function(link) {
            link.classList.remove('active');
            const href = link.getAttribute('href');
            if (href === '#' && activeSection === 'home') {
                link.classList.add('active');
            } else if (href === '#' + activeSection) {
                link.classList.add('active');
            }
        });
    }

    let scrollTimeout;
    window.addEventListener('scroll', function() {
        if (scrollTimeout) return;
        scrollTimeout = requestAnimationFrame(function() {
            updateActiveLink();
            scrollTimeout = null;
        });
    });

    // Initial active link
    updateActiveLink();
});

// "Find Your Booking" modal: open/close, focus trap, and an email + OTP
// lookup against the guest's own bookings. This modal used to also host a
// "Quick Links" mode for site navigation, but every one of those links
// (Home, Rates, Features, FAQ, Find Us) already exists as a normal link in
// the nav bar above — the modal is booking lookup only now.
(function () {
    // Elements
    const navSearchTrigger = document.getElementById('navSearchTrigger');
    const navSearchInputMobile = document.getElementById('navSearchInputMobile'); // trigger-only, see below
    const searchModal = document.getElementById('searchModal');
    const searchModalClose = document.getElementById('searchModalClose');
    const searchModalHint = document.getElementById('searchModalHint');
    const searchModalResults = document.getElementById('searchModalResults');

    const emailForm = document.getElementById('bookingSearchEmailForm');
    const emailInput = document.getElementById('searchEmailInput');
    const emailClear = document.getElementById('searchEmailClear');
    const sendCodeBtn = document.getElementById('bookingSearchSendCode');

    const codeForm = document.getElementById('bookingSearchCodeForm');
    const codeGroup = document.getElementById('searchCodeGroup');
    const otpInputs = codeGroup ? Array.from(codeGroup.querySelectorAll('.otp-input')) : [];
    const verifyCodeBtn = document.getElementById('bookingSearchVerifyCode');
    const resendCodeBtn = document.getElementById('bookingSearchResendCode');
    const changeEmailBtn = document.getElementById('bookingSearchChangeEmail');

    const requestCodeUrl = searchModal ? searchModal.dataset.requestCodeUrl : null;
    const verifyCodeUrl = searchModal ? searchModal.dataset.verifyCodeUrl : null;
    const csrfToken = document.querySelector('meta[name="csrf-token"]');
    const csrfTokenValue = csrfToken ? csrfToken.content : null;

    let previousActive = null;
    let resendCooldownTimer = null;
    let currentEmail = '';

    function showEmailStep() {
        if (emailForm) emailForm.hidden = false;
        if (codeForm) codeForm.hidden = true;
        if (searchModalHint) searchModalHint.textContent = "Enter the email you used when booking — we'll send you a 4-digit code.";
        renderPrompt();
    }

    function showCodeStep() {
        if (emailForm) emailForm.hidden = true;
        if (codeForm) codeForm.hidden = false;
        if (searchModalHint) searchModalHint.textContent = `We sent a 4-digit code to ${currentEmail}.`;
        clearOtpInputs();
        renderMessage('Enter the code from your email.');
        window.setTimeout(() => {
            if (otpInputs[0]) otpInputs[0].focus();
        }, 50);
    }

    function startResendCooldown(seconds) {
        if (!resendCodeBtn) return;
        let remaining = seconds;
        resendCodeBtn.disabled = true;
        resendCodeBtn.textContent = `Resend code (${remaining}s)`;

        if (resendCooldownTimer) clearInterval(resendCooldownTimer);
        resendCooldownTimer = setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(resendCooldownTimer);
                resendCooldownTimer = null;
                resendCodeBtn.disabled = false;
                resendCodeBtn.textContent = 'Resend code';
                return;
            }
            resendCodeBtn.textContent = `Resend code (${remaining}s)`;
        }, 1000);
    }

    function openSearchModal() {
        if (!searchModal) return;
        previousActive = document.activeElement;
        searchModal.hidden = false;
        searchModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');

        if (emailInput) emailInput.value = '';
        if (emailClear) emailClear.hidden = true;
        currentEmail = '';
        showEmailStep();

        window.setTimeout(() => {
            if (emailInput) emailInput.focus();
        }, 50);
        document.addEventListener('keydown', onKeyDown);
        searchModal.addEventListener('click', onOverlayClick);
        trapFocus(true);
    }

    function closeSearchModal() {
        if (!searchModal) return;
        searchModal.hidden = true;
        searchModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('no-scroll');
        document.removeEventListener('keydown', onKeyDown);
        searchModal.removeEventListener('click', onOverlayClick);
        trapFocus(false);
        if (resendCooldownTimer) {
            clearInterval(resendCooldownTimer);
            resendCooldownTimer = null;
        }

        // Restoring focus to the mobile trigger would immediately re-open
        // this modal — it opens the modal on focus, so .focus()-ing it here
        // just reopens what we're trying to close. Send focus to whatever
        // actually triggered the modal instead (the desktop icon, if that's
        // what opened it), or drop it entirely rather than loop.
        if (previousActive === navSearchInputMobile) {
            if (navSearchTrigger) navSearchTrigger.focus();
        } else if (previousActive && previousActive.focus) {
            previousActive.focus();
        }
    }

    function onOverlayClick(e) {
        // close when clicking outside the modal box
        if (e.target === searchModal) closeSearchModal();
    }

    function onKeyDown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeSearchModal();
        }
    }

    function trapFocus(enable) {
        if (!enable) {
            document.removeEventListener('focus', focusHandler, true);
            return;
        }
        document.addEventListener('focus', focusHandler, true);
    }

    function focusHandler(e) {
        if (!searchModal || searchModal.hidden) return;
        if (!searchModal.contains(e.target)) {
            e.stopPropagation();
            const target = codeForm && !codeForm.hidden ? otpInputs[0] : emailInput;
            if (target) target.focus();
        }
    }

    // ---------- 4-digit code boxes ----------

    function getOtpCode() {
        return otpInputs.map((input) => input.value).join('');
    }

    function clearOtpInputs() {
        otpInputs.forEach((input) => {
            input.value = '';
        });
    }

    function setOtpCode(digits) {
        otpInputs.forEach((input, i) => {
            input.value = digits[i] || '';
        });
    }

    otpInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            // Keep only the last digit typed, strip anything non-numeric
            // (covers keyboards/IMEs that can slip in more than one
            // character per input event).
            const digits = input.value.replace(/\D/g, '');
            input.value = digits.slice(-1);

            if (input.value && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }

            if (otpInputs.every((box) => box.value)) {
                verifyCode();
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !input.value && index > 0) {
                e.preventDefault();
                otpInputs[index - 1].focus();
                otpInputs[index - 1].value = '';
            } else if (e.key === 'ArrowLeft' && index > 0) {
                e.preventDefault();
                otpInputs[index - 1].focus();
            } else if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                e.preventDefault();
                otpInputs[index + 1].focus();
            }
        });

        input.addEventListener('paste', (e) => {
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            const digits = pasted.replace(/\D/g, '').slice(0, otpInputs.length);
            if (!digits) return;
            e.preventDefault();
            setOtpCode(digits);
            const nextEmpty = otpInputs.findIndex((box) => !box.value);
            const focusTarget = nextEmpty === -1 ? otpInputs[otpInputs.length - 1] : otpInputs[nextEmpty];
            focusTarget.focus();
            if (digits.length === otpInputs.length) {
                verifyCode();
            }
        });
    });

    // ---------- Results rendering ----------

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    // Small helper for building an element with a class + text content in
    // one line — same pattern used in admin-customers.js.
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function resultsContainer() {
        return searchModalResults ? searchModalResults.querySelector('.booking-search-results-inner') : null;
    }

    function renderMessage(text) {
        const inner = resultsContainer();
        if (!inner) return;
        inner.innerHTML = '';
        const p = document.createElement('div');
        p.className = 'booking-search-results-empty';
        p.textContent = text;
        inner.appendChild(p);
    }

    function renderPrompt() {
        renderMessage('Enter your email, then hit "Send Code".');
    }

    // Builds one booking result card entirely with createElement/textContent
    // rather than an innerHTML template string — this is guest-facing (not
    // admin-only like the equipment/customers tables), so there's no
    // HTML-injection sink here at all regardless of what a static scanner
    // can verify about escaping.
    function buildBookingResultRow(b) {
        const row = el('div', 'booking-search-result');

        const top = el('div', 'booking-search-result-top');
        const main = el('div', 'booking-search-result-main');
        main.appendChild(el('span', 'booking-search-result-court', b.court));
        const datetime = el('span', 'booking-search-result-datetime');
        datetime.append(`${b.date} \u00b7 ${b.time}`);
        main.appendChild(datetime);
        top.appendChild(main);

        const statusClass = b.status === 'paid' ? 'status-paid'
            : b.status === 'cancelled' ? 'status-cancelled'
            : 'status-pending';
        const statusLabel = b.status.charAt(0).toUpperCase() + b.status.slice(1);
        top.appendChild(el('span', `status ${statusClass}`, statusLabel));
        row.appendChild(top);

        const equipmentList = b.equipment || [];
        if (equipmentList.length) {
            const equipWrap = el('div', 'booking-search-result-equipment');
            equipmentList.forEach((item) => {
                const chip = el('span', 'booking-search-equipment-chip');
                chip.append(`${item.quantity}\u00d7 ${item.name}`);
                equipWrap.appendChild(chip);
            });
            row.appendChild(equipWrap);
        }

        if (b.payment) {
            const paymentWrap = el('div', 'booking-search-result-payment');
            const methodLabel = b.payment.charAt(0).toUpperCase() + b.payment.slice(1);
            paymentWrap.appendChild(el('span', 'booking-search-result-payment-method', methodLabel));
            if (b.reference) {
                const refSpan = el('span', 'booking-search-result-payment-ref');
                refSpan.append(`Ref: ${b.reference}`);
                paymentWrap.appendChild(refSpan);
            }
            row.appendChild(paymentWrap);
        }

        const footer = el('div', 'booking-search-result-footer');
        footer.appendChild(el('span', 'booking-search-result-total-label', 'Total'));
        const totalAmount = Number(b.amount || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        const totalSpan = el('span', 'booking-search-result-total-amount');
        totalSpan.append(`\u20b1${totalAmount}`);
        footer.appendChild(totalSpan);
        row.appendChild(footer);

        return row;
    }

    function renderResults(bookings) {
        const inner = resultsContainer();
        if (!inner) return;
        inner.innerHTML = '';

        if (!bookings.length) {
            renderMessage('No bookings found for that email.');
            return;
        }

        bookings.forEach((b) => {
            inner.appendChild(buildBookingResultRow(b));
        });
    }

    // ---------- Email + OTP ----------

    function isEmailReady() {
        const email = emailInput ? emailInput.value.trim() : '';
        return email.includes('@') && email.includes('.');
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfTokenValue || '',
            },
            body: JSON.stringify(body),
        });

        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    }

    async function requestCode() {
        const email = emailInput ? emailInput.value.trim() : '';

        if (!isEmailReady()) {
            renderMessage('Enter a complete email address.');
            return;
        }

        if (!requestCodeUrl) {
            renderMessage('Booking search is unavailable right now.');
            return;
        }

        if (sendCodeBtn) sendCodeBtn.disabled = true;
        renderMessage('Sending code…');

        try {
            const { ok, status, data } = await postJson(requestCodeUrl, { email });

            if (!ok && status !== 429) {
                renderMessage(data.message || 'Something went wrong. Please try again.');
                return;
            }

            currentEmail = email;
            showCodeStep();
            startResendCooldown(60);

            if (status === 429) {
                renderMessage(data.message || 'Please wait a bit before requesting another code.');
            }
        } catch (err) {
            console.error(err);
            renderMessage('Something went wrong. Please try again.');
        } finally {
            if (sendCodeBtn) sendCodeBtn.disabled = false;
        }
    }

    async function verifyCode() {
        const code = getOtpCode();

        if (!/^\d{4}$/.test(code)) {
            renderMessage('Enter the 4-digit code from your email.');
            return;
        }

        if (!verifyCodeUrl) {
            renderMessage('Booking search is unavailable right now.');
            return;
        }

        if (verifyCodeBtn) verifyCodeBtn.disabled = true;
        renderMessage('Verifying…');

        try {
            const { ok, data } = await postJson(verifyCodeUrl, { email: currentEmail, code });

            if (!ok) {
                renderMessage(data.message || 'Something went wrong. Please try again.');
                return;
            }

            renderResults(data.bookings || []);
        } catch (err) {
            console.error(err);
            renderMessage('Something went wrong. Please try again.');
        } finally {
            if (verifyCodeBtn) verifyCodeBtn.disabled = false;
        }
    }

    if (emailClear) {
        emailClear.addEventListener('click', () => {
            if (!emailInput) return;
            emailInput.value = '';
            emailClear.hidden = true;
            emailInput.focus();
        });
    }

    if (emailInput) {
        emailInput.addEventListener('input', () => {
            if (emailClear) emailClear.hidden = !emailInput.value;
        });
    }

    if (emailForm) {
        emailForm.addEventListener('submit', (e) => {
            e.preventDefault();
            requestCode();
        });
    }

    if (codeForm) {
        codeForm.addEventListener('submit', (e) => {
            e.preventDefault();
            verifyCode();
        });
    }

    if (resendCodeBtn) {
        resendCodeBtn.addEventListener('click', () => {
            if (resendCodeBtn.disabled) return;
            requestCode();
        });
    }

    if (changeEmailBtn) {
        changeEmailBtn.addEventListener('click', () => {
            if (resendCooldownTimer) {
                clearInterval(resendCooldownTimer);
                resendCooldownTimer = null;
            }
            if (resendCodeBtn) {
                resendCodeBtn.disabled = false;
                resendCodeBtn.textContent = 'Resend code';
            }
            currentEmail = '';
            clearOtpInputs();
            showEmailStep();
            if (emailInput) emailInput.focus();
        });
    }

    // Wire up open/close triggers
    if (navSearchTrigger) {
        navSearchTrigger.addEventListener('click', (e) => {
            e.preventDefault();
            openSearchModal();
        });
    }

    if (navSearchInputMobile) {
        // The mobile nav's search field is a trigger, not a real input
        // (it's marked readonly in the markup) — focusing or clicking it
        // just opens this modal, then immediately blurs itself so the
        // on-screen keyboard doesn't pop up and stack under the modal.
        navSearchInputMobile.addEventListener('focus', (e) => {
            e.preventDefault();
            openSearchModal();
            navSearchInputMobile.blur();
        });
        navSearchInputMobile.addEventListener('click', (e) => {
            e.preventDefault();
            openSearchModal();
            navSearchInputMobile.blur();
        });
    }

    if (searchModalClose) searchModalClose.addEventListener('click', closeSearchModal);
})();