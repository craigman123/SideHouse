document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('membershipModal');
    const openButton = document.getElementById('openMembershipModal');
    const closeButton = document.getElementById('membershipModalClose');
    const cancelButton = document.getElementById('cancelMembershipModal');
    const form = document.getElementById('membershipForm');
    const submitButton = document.getElementById('saveMembershipButton');
    const discountType = document.getElementById('membershipDiscountType');
    const discountValue = document.getElementById('membershipDiscountValue');
    const discountSuffix = document.getElementById('membershipDiscountSuffix');
    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');

    if (!modal || !openButton || !form) return;

    let returnFocus = openButton;
    let previousBodyOverflow = '';

    function showToast(message) {
        if (!toastContainer || !message) return;
        const toast = document.createElement('div');
        toast.className = 'toast toast-success';
        const text = document.createElement('span');
        text.className = 'toast-message';
        text.textContent = message;
        toast.appendChild(text);
        toastContainer.appendChild(toast);
        window.setTimeout(() => toast.remove(), 4000);
    }

    function configureDiscountValue() {
        if (!discountType || !discountValue || !discountSuffix) return;
        const percentage = discountType.value === 'percentage';
        discountValue.min = percentage ? '1' : '0.01';
        discountValue.max = percentage ? '100' : '99999999.99';
        discountValue.step = '0.01';
        discountSuffix.textContent = percentage ? '%' : 'PHP';
    }

    function focusableElements() {
        return Array.from(modal.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((element) => element.getClientRects().length > 0);
    }

    function openModal(restoreErrors = false) {
        returnFocus = restoreErrors
            ? openButton
            : (document.activeElement instanceof HTMLElement ? document.activeElement : openButton);
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('open');
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const firstInvalid = restoreErrors ? modal.querySelector('[aria-invalid="true"]') : null;
        (firstInvalid || modal.querySelector('input, textarea, select, button')).focus();
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        modal.hidden = true;
        document.body.style.overflow = previousBodyOverflow;
        if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus();
    }

    openButton.addEventListener('click', () => openModal());
    closeButton?.addEventListener('click', closeModal);
    cancelButton?.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key !== 'Tab') return;
        const focusable = focusableElements();
        if (!focusable.length) {
            event.preventDefault();
            modal.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const activeIndex = focusable.indexOf(document.activeElement);
        if (activeIndex === -1) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    discountType?.addEventListener('change', configureDiscountValue);
    configureDiscountValue();

    form.addEventListener('submit', () => {
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
        }
    });

    if (modal.classList.contains('open')) {
        openModal(true);
    }

    showToast(flash?.dataset.success || '');
});
