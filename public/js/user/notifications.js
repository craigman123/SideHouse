document.addEventListener('DOMContentLoaded', () => {

    /* ---------- Flash message toast (mirrors profile.js) ---------- */

    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');

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

    if (flash) {
        const success = flash.dataset.success;
        if (success) showToast(success, 'success');
    }

    /* ---------- Mark a single notification as read ---------- */

    function csrfHeaders() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        return token ? { 'X-CSRF-TOKEN': token } : {};
    }

    document.querySelectorAll('.notification-mark-read-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const url = btn.dataset.markReadUrl;
            const row = btn.closest('.notification-row');
            if (!url || !row) return;

            btn.disabled = true;

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { Accept: 'application/json', ...csrfHeaders() },
                });
                if (!res.ok) throw new Error('Request failed');

                row.classList.remove('is-unread');
                btn.remove();

                // Bell badge (in the shared nav) polls its own unread
                // count independently — see notification-bell.js — so
                // no need to touch it directly from here.
            } catch (err) {
                console.error(err);
                btn.disabled = false;
                showToast('Could not mark as read. Try again.', 'error');
            }
        });
    });
});
