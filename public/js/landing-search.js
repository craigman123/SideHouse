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

// "Find Your Booking" modal: open/close, focus trap, and a phone/email
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
    const searchModalResults = document.getElementById('searchModalResults');
    const bookingSearchForm = document.getElementById('bookingSearchForm');
    const phoneInput = document.getElementById('searchPhoneInput');
    const emailInput = document.getElementById('searchEmailInput');
    const phoneClear = document.getElementById('searchPhoneClear');
    const emailClear = document.getElementById('searchEmailClear');
    const bookingsSearchUrl = searchModal ? searchModal.dataset.bookingsSearchUrl : null;

    let previousActive = null;
    let searchDebounce = null;

    function openSearchModal() {
        if (!searchModal) return;
        previousActive = document.activeElement;
        searchModal.hidden = false;
        searchModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');

        if (phoneInput) phoneInput.value = '';
        if (emailInput) emailInput.value = '';
        if (phoneClear) phoneClear.hidden = true;
        if (emailClear) emailClear.hidden = true;
        renderPrompt();

        window.setTimeout(() => {
            if (phoneInput) phoneInput.focus();
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
        if (searchDebounce) {
            clearTimeout(searchDebounce);
            searchDebounce = null;
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
            if (phoneInput) phoneInput.focus();
        }
    }

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
        renderMessage('Enter your phone number or email, then hit "Find Bookings".');
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
            renderMessage('No bookings found for that phone number or email.');
            return;
        }

        bookings.forEach((b) => {
            inner.appendChild(buildBookingResultRow(b));
        });
    }

    // ---------- Search ----------

    function isReady() {
        const phone = phoneInput ? phoneInput.value.trim() : '';
        const email = emailInput ? emailInput.value.trim() : '';
        const digits = phone.replace(/\D/g, '');
        // Same readiness bar the backend re-checks — avoids firing a
        // request for a half-typed digit string or a bare '@' that will
        // always come back empty anyway.
        const phoneReady = digits.length >= 7;
        const emailReady = email.includes('@') && email.includes('.');
        return phoneReady || emailReady;
    }

    async function runSearch() {
        const phone = phoneInput ? phoneInput.value.trim() : '';
        const email = emailInput ? emailInput.value.trim() : '';

        if (!phone && !email) {
            renderPrompt();
            return;
        }

        if (!isReady()) {
            renderMessage('Enter a complete phone number or email address.');
            return;
        }

        if (!bookingsSearchUrl) {
            renderMessage('Booking search is unavailable right now.');
            return;
        }

        renderMessage('Searching…');

        try {
            const params = new URLSearchParams();
            if (phone) params.set('phone', phone);
            if (email) params.set('email', email);

            const res = await fetch(`${bookingsSearchUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });

            if (!res.ok) {
                renderMessage('Something went wrong. Please try again.');
                return;
            }

            const data = await res.json();
            renderResults(data.bookings || []);
        } catch (err) {
            console.error(err);
            renderMessage('Something went wrong. Please try again.');
        }
    }

    function debouncedSearch() {
        if (searchDebounce) clearTimeout(searchDebounce);
        searchDebounce = setTimeout(runSearch, 400);
    }

    function wireField(input, clearBtn) {
        if (!input) return;

        input.addEventListener('input', () => {
            if (clearBtn) clearBtn.hidden = !input.value;
            debouncedSearch();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                input.value = '';
                clearBtn.hidden = true;
                input.focus();
                if (searchDebounce) clearTimeout(searchDebounce);
                if (!phoneInput.value && !emailInput.value) {
                    renderPrompt();
                } else {
                    runSearch();
                }
            });
        }
    }

    wireField(phoneInput, phoneClear);
    wireField(emailInput, emailClear);

    if (bookingSearchForm) {
        bookingSearchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (searchDebounce) clearTimeout(searchDebounce);
            runSearch();
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