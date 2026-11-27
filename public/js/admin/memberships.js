document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('membershipModal');
    const summaryModal = document.getElementById('membershipSummaryModal');
    const openButton = document.getElementById('openMembershipModal');
    const closeButton = document.getElementById('membershipModalClose');
    const cancelButton = document.getElementById('cancelMembershipModal');
    const summaryCloseButton = document.getElementById('membershipSummaryClose');
    const backButton = document.getElementById('backToMembershipForm');
    const previewButton = document.getElementById('previewMembershipButton');
    const confirmButton = document.getElementById('confirmMembershipButton');
    const form = document.getElementById('membershipForm');
    const discountType = document.getElementById('membershipDiscountType');
    const discountValue = document.getElementById('membershipDiscountValue');
    const discountSuffix = document.getElementById('membershipDiscountSuffix');
    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');
    const qrImage = document.getElementById('membershipSummaryQr');

    if (!modal || !summaryModal || !openButton || !form || !previewButton || !confirmButton) return;

    const errorMessages = new Map(
        Array.from(form.querySelectorAll('[data-membership-error]'))
            .map((element) => [element.dataset.membershipError, element])
    );
    const inputs = new Map(
        Array.from(form.elements)
            .filter((element) => element.name)
            .map((element) => [element.name, element])
    );
    let activeModal = null;
    let returnFocus = openButton;
    let previousBodyOverflow = '';
    let allowStoreSubmit = false;

    function showToast(message, type = 'success') {
        if (!toastContainer || !message) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
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

    function setModalVisible(target, visible) {
        target.hidden = !visible;
        target.setAttribute('aria-hidden', visible ? 'false' : 'true');
        target.classList.toggle('open', visible);
    }

    function focusableElements(target) {
        return Array.from(target.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((element) => element.getClientRects().length > 0);
    }

    function openFormModal(restoreErrors = false, focusTarget = null) {
        if (!activeModal) {
            previousBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }

        activeModal = modal;
        setModalVisible(summaryModal, false);
        setModalVisible(modal, true);

        const firstInvalid = restoreErrors ? modal.querySelector('[aria-invalid="true"]') : null;
        const target = focusTarget || firstInvalid || document.getElementById('membershipTitle');
        target?.focus();
    }

    function openSummaryModal() {
        activeModal = summaryModal;
        setModalVisible(modal, false);
        setModalVisible(summaryModal, true);
        summaryCloseButton?.focus();
    }

    function returnToForm() {
        activeModal = modal;
        setModalVisible(summaryModal, false);
        setModalVisible(modal, true);
        previewButton.focus();
    }

    function closeFormModal() {
        if (activeModal !== modal) return;
        setModalVisible(modal, false);
        activeModal = null;
        document.body.style.overflow = previousBodyOverflow;
        if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus();
    }

    function clearFieldError(name) {
        const message = errorMessages.get(name);
        const input = inputs.get(name);
        if (message) message.textContent = '';
        if (input) input.setAttribute('aria-invalid', 'false');
    }

    function displayErrors(errors = {}) {
        let firstInvalid = null;
        let unmappedMessage = '';

        for (const [field, messages] of Object.entries(errors)) {
            const message = Array.isArray(messages) ? messages[0] : messages;
            const fieldMessage = errorMessages.get(field);
            const input = inputs.get(field);

            if (!fieldMessage || typeof message !== 'string') {
                unmappedMessage = typeof message === 'string' ? message : unmappedMessage;
                continue;
            }

            fieldMessage.textContent = message;
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                if (!firstInvalid) firstInvalid = input;
            }
        }

        if (unmappedMessage) showToast(unmappedMessage, 'error');
        return firstInvalid;
    }

    function formatCurrency(value) {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(Number(value));
    }

    function formatDate(value) {
        if (!value) return 'No expiry';
        const [year, month, day] = value.split('-').map(Number);
        return new Intl.DateTimeFormat('en-PH', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(new Date(year, month - 1, day));
    }

    function svgDataUri(svg) {
        const bytes = new TextEncoder().encode(svg);
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return `data:image/svg+xml;base64,${window.btoa(binary)}`;
    }

    function renderSummary(data) {
        const membership = data.membership;
        document.getElementById('membershipSummaryName').textContent = membership.title;
        document.getElementById('membershipSummaryDescription').textContent = membership.description || 'No description';
        document.getElementById('membershipSummaryPrice').textContent = formatCurrency(membership.price);
        document.getElementById('membershipSummaryDiscount').textContent = membership.discount_type === 'percentage'
            ? `Percentage: ${Number(membership.discount_value).toLocaleString('en-PH')}%`
            : `Fixed price: ${formatCurrency(membership.discount_value)}`;
        document.getElementById('membershipSummaryPlayers').textContent = String(membership.players);
        document.getElementById('membershipSummaryExpiry').textContent = formatDate(membership.expiry_date);
        document.getElementById('membershipSummaryCreated').textContent = membership.created_at;
        qrImage.src = svgDataUri(data.qr_svg);
        qrImage.hidden = false;
        openSummaryModal();
    }

    async function requestSummary() {
        for (const name of errorMessages.keys()) clearFieldError(name);
        previewButton.disabled = true;
        previewButton.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(form.dataset.summaryUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (response.status === 422) {
                displayErrors(data.errors);
                const firstInvalid = modal.querySelector('[aria-invalid="true"]');
                firstInvalid?.focus();
                return;
            }

            if (!response.ok) {
                showToast(data.message || 'Unable to prepare the membership summary. Please try again.', 'error');
                return;
            }

            if (!data.membership || typeof data.qr_svg !== 'string') {
                showToast('The membership summary could not be prepared. Please try again.', 'error');
                return;
            }

            renderSummary(data);
        } catch {
            showToast('Unable to prepare the membership summary. Check your connection and try again.', 'error');
        } finally {
            previewButton.disabled = false;
            previewButton.removeAttribute('aria-busy');
        }
    }

    openButton.addEventListener('click', () => {
        returnFocus = openButton;
        openFormModal();
    });
    closeButton?.addEventListener('click', closeFormModal);
    cancelButton?.addEventListener('click', closeFormModal);
    summaryCloseButton?.addEventListener('click', returnToForm);
    backButton?.addEventListener('click', returnToForm);
    previewButton.addEventListener('click', requestSummary);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeFormModal();
    });
    summaryModal.addEventListener('click', (event) => {
        if (event.target === summaryModal) returnToForm();
    });

    document.addEventListener('keydown', (event) => {
        if (!activeModal) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            if (activeModal === summaryModal) returnToForm();
            else closeFormModal();
            return;
        }

        if (event.key !== 'Tab') return;
        const focusable = focusableElements(activeModal);
        if (!focusable.length) {
            event.preventDefault();
            activeModal.focus();
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

    form.addEventListener('input', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) {
            clearFieldError(target.name);
        }
    });
    discountType?.addEventListener('change', configureDiscountValue);
    configureDiscountValue();

    form.addEventListener('submit', (event) => {
        if (!allowStoreSubmit) {
            event.preventDefault();
            return;
        }
        allowStoreSubmit = false;
        confirmButton.disabled = true;
        confirmButton.setAttribute('aria-busy', 'true');
    });

    confirmButton.addEventListener('click', () => {
        allowStoreSubmit = true;
        form.requestSubmit();
        if (allowStoreSubmit) {
            allowStoreSubmit = false;
            const invalidFields = Array.from(form.elements)
                .filter((element) => element.willValidate && !element.validity.valid);
            const errors = Object.fromEntries(invalidFields.map((element) => [
                element.name,
                [element.validationMessage],
            ]));
            const firstInvalid = displayErrors(errors);
            returnToForm();
            firstInvalid?.focus();
        }
    });

    if (modal.classList.contains('open')) {
        returnFocus = openButton;
        openFormModal(true);
    }

    showToast(flash?.dataset.success || '');
});
