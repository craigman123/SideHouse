// cancel-booking.js – wires up the "Cancel booking" confirmation modal.
// Works on any page that has a #cancelModal (with a data-cancel-url
// template containing ':id') and one or more [data-cancel] buttons sitting
// inside an element carrying data-booking-id / data-booking.
//
// When the trigger button carries data-paid="1" (paid/confirmed bookings)
// the modal shows a refund note explaining credits will be returned.

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('cancelModal');
    if (!modal) return; // this page doesn't have the cancel flow

    const modalText       = document.getElementById('modalText');
    const modalRefundNote = document.getElementById('modalRefundNote');
    const modalClose      = document.getElementById('modalClose');
    const modalKeep       = document.getElementById('modalKeep');
    const modalConfirm    = document.getElementById('modalConfirm');
    const toastContainer  = document.getElementById('toastContainer');

    const cancelUrlTemplate = modal.dataset.cancelUrl;
    let isCancelling = false;
    const lockedControls = [];
    let originalConfirmHTML = '';

    let pendingRow = null;
    let pendingId  = null;

    function lockPage() {
        document.querySelectorAll('button, a, input, select, textarea').forEach((control) => {
            if (control === modalConfirm) return;

            const state = {
                control,
                disabled: control.disabled,
                href: control.getAttribute('href'),
                tabindex: control.getAttribute('tabindex'),
                ariaDisabled: control.getAttribute('aria-disabled'),
            };
            lockedControls.push(state);
            control.disabled = true;
            control.classList.add('is-cancelling-disabled');
            control.setAttribute('aria-disabled', 'true');
            control.setAttribute('tabindex', '-1');
            if (control.tagName === 'A') control.removeAttribute('href');
        });

        modal.setAttribute('aria-busy', 'true');
        modalClose?.setAttribute('disabled', '');
        modalKeep?.setAttribute('disabled', '');
        modalConfirm.disabled = true;
        modalConfirm.setAttribute('aria-busy', 'true');
        modalConfirm.classList.add('is-cancelling-disabled');
        modalConfirm.innerHTML = '<span class="cancel-spinner" aria-hidden="true"></span><span>Cancelling...</span>';
    }

    function unlockPage() {
        lockedControls.splice(0).forEach(({ control, disabled, href, tabindex, ariaDisabled }) => {
            control.disabled = disabled;
            control.classList.remove('is-cancelling-disabled');
            if (tabindex === null) control.removeAttribute('tabindex');
            else control.setAttribute('tabindex', tabindex);
            if (ariaDisabled === null) control.removeAttribute('aria-disabled');
            else control.setAttribute('aria-disabled', ariaDisabled);
            if (href !== null && control.tagName === 'A') control.setAttribute('href', href);
        });

        modal.removeAttribute('aria-busy');
        modalClose?.removeAttribute('disabled');
        modalKeep?.removeAttribute('disabled');
        modalConfirm.disabled = false;
        modalConfirm.removeAttribute('aria-busy');
        modalConfirm.classList.remove('is-cancelling-disabled');
        modalConfirm.innerHTML = originalConfirmHTML;
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    // Same robust CSRF strategy as book.js: prefer the meta tag, fall back
    // to Laravel's own XSRF-TOKEN cookie so a missing meta tag can't cause
    // a 419 mismatch.
    function csrfHeaders() {
        const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (metaToken) return { 'X-CSRF-TOKEN': metaToken };

        const cookieToken = getCookie('XSRF-TOKEN');
        if (cookieToken) return { 'X-XSRF-TOKEN': cookieToken };

        return {};
    }

    function openModal(row, isPaid) {
        pendingRow = row;
        pendingId  = row.dataset.bookingId;

        const label = row.dataset.booking || 'this booking';
        if (isPaid) {
            modalText.textContent = `Cancel ${label}? This will free up the slot for other players and issue a credit refund. This can't be undone.`;
        } else {
            modalText.textContent = `Cancel ${label}? This will free up the slot for other players. This can't be undone.`;
        }

        if (modalRefundNote) {
            modalRefundNote.hidden = !isPaid;
        }

        modal.classList.add('open');
    }

    function closeModal(force = false) {
        if (isCancelling && !force) return;
        modal.classList.remove('open');
        pendingRow = null;
        pendingId  = null;
    }

    // Event delegation: catches Cancel buttons anywhere on the page,
    // including ones in paginated/filtered tables re-rendered by the server.
    document.addEventListener('click', (e) => {
        if (isCancelling) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        const trigger = e.target.closest('[data-cancel]');
        if (!trigger) return;

        const row = trigger.closest('[data-booking-id]');
        if (!row) return;

        const isPaid = trigger.dataset.paid === '1';
        openModal(row, isPaid);
    });

    modalClose?.addEventListener('click', () => closeModal());
    modalKeep?.addEventListener('click', () => closeModal());
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('open')) {
            event.preventDefault();
            closeModal();
        }
    });

    modalConfirm?.addEventListener('click', async () => {
        if (isCancelling || !pendingId || !cancelUrlTemplate) return;

        isCancelling = true;
        originalConfirmHTML = modalConfirm.innerHTML;
        lockPage();
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 15000);

        try {
            const res = await fetch(cancelUrlTemplate.replace(':id', pendingId), {
                method: 'POST',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                showToast(data.message || 'Could not cancel this booking.', 'error');
                return;
            }

            showToast(data.message || 'Booking cancelled.', 'success');

            if (pendingRow) {
                pendingRow.classList.add('row-removing');
            }

            closeModal(true);
            setTimeout(() => window.location.reload(), 700);
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
        } finally {
            window.clearTimeout(timeoutId);
            isCancelling = false;
            unlockPage();
        }
    });

    function showToast(message, type = 'success') {
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message"></span>
            <button type="button" class="toast-close btn-ghost-danger" aria-label="Dismiss">&times;</button>
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
});
