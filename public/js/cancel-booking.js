// cancel-booking.js — wires up the "Cancel booking" confirmation modal.
// Works on any page that has a #cancelModal (with a data-cancel-url
// template containing ':id') and one or more [data-cancel] buttons sitting
// inside an element carrying data-booking-id / data-booking.

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('cancelModal');
    if (!modal) return; // this page doesn't have the cancel flow

    const modalText = document.getElementById('modalText');
    const modalClose = document.getElementById('modalClose');
    const modalKeep = document.getElementById('modalKeep');
    const modalConfirm = document.getElementById('modalConfirm');
    const toastContainer = document.getElementById('toastContainer');

    const cancelUrlTemplate = modal.dataset.cancelUrl;

    let pendingRow = null;
    let pendingId = null;

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

    function openModal(row) {
        pendingRow = row;
        pendingId = row.dataset.bookingId;

        const label = row.dataset.booking || 'this booking';
        modalText.textContent = `Cancel ${label}? This will free up the slot for other players. This can't be undone.`;

        modal.classList.add('open');
    }

    function closeModal() {
        modal.classList.remove('open');
        pendingRow = null;
        pendingId = null;
    }

    // Event delegation: catches Cancel buttons anywhere on the page,
    // including ones in paginated/filtered tables re-rendered by the server.
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-cancel]');
        if (!trigger) return;

        const row = trigger.closest('[data-booking-id]');
        if (!row) return;

        openModal(row);
    });

    modalClose?.addEventListener('click', closeModal);
    modalKeep?.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    modalConfirm?.addEventListener('click', async () => {
        if (!pendingId || !cancelUrlTemplate) return;

        modalConfirm.disabled = true;
        const originalLabel = modalConfirm.textContent;
        modalConfirm.textContent = 'Cancelling…';

        try {
            const res = await fetch(cancelUrlTemplate.replace(':id', pendingId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                showToast(data.message || 'Could not cancel this booking.', 'error');
                modalConfirm.disabled = false;
                modalConfirm.textContent = originalLabel;
                return;
            }

            showToast(data.message || 'Booking cancelled.', 'success');

            if (pendingRow) {
                pendingRow.classList.add('row-removing');
            }

            closeModal();
            setTimeout(() => window.location.reload(), 700);
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
            modalConfirm.disabled = false;
            modalConfirm.textContent = originalLabel;
        }
    });

    function showToast(message, type = 'success') {
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
});
