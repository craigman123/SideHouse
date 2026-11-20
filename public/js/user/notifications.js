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

    function initializeNotificationFilters() {
        const rows = Array.from(document.querySelectorAll('.notification-row'));
        const buttons = Array.from(document.querySelectorAll('[data-notification-filter]'));
        const emptyMessage = document.querySelector('.notifications-filter-empty');
        let activeFilter = 'all';

        function applyFilter() {
            const visibleRows = rows.filter((row) => {
                const visible = activeFilter === 'all' || row.classList.contains('is-unread');
                row.hidden = !visible;
                return visible;
            });

            if (emptyMessage) emptyMessage.hidden = activeFilter !== 'unread' || visibleRows.length > 0;
            buttons.forEach((button) => {
                const active = button.dataset.notificationFilter === activeFilter;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
        }

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                activeFilter = button.dataset.notificationFilter === 'unread' ? 'unread' : 'all';
                applyFilter();
            });
        });

        const unreadObserver = new MutationObserver(applyFilter);
        rows.forEach((row) => unreadObserver.observe(row, { attributes: true, attributeFilter: ['class'] }));
    }

    function initializeNotificationsLoading() {
        const list = document.querySelector('.notifications-list');
        const skeleton = document.querySelector('.notifications-loading');
        if (!list || !skeleton) return;

        function showLoading() {
            list.hidden = true;
            list.setAttribute('aria-busy', 'true');
            skeleton.hidden = false;
        }

        function hideLoading() {
            list.hidden = false;
            list.setAttribute('aria-busy', 'false');
            skeleton.hidden = true;
        }

        document.querySelector('.notifications-mark-all-form')?.addEventListener('submit', showLoading);
        document.querySelectorAll('.pagination-wrapper a').forEach((link) => link.addEventListener('click', showLoading));
        window.addEventListener('pageshow', hideLoading);
    }

    initializeNotificationFilters();
    initializeNotificationsLoading();
});
