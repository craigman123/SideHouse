// back-guard.js — include only on authenticated dashboard pages
(function () {
    // Push a duplicate state so back button triggers popstate here first,
    // instead of actually navigating to the previous history entry.
    history.pushState({ backGuard: true }, '', location.href);

    window.addEventListener('popstate', () => {
        // Immediately re-trap: push state again so the URL/page doesn't change
        history.pushState({ backGuard: true }, '', location.href);
        showLogoutConfirmModal();
    });

    function showLogoutConfirmModal() {
        document.getElementById('logout-confirm-modal').classList.remove('hidden');
    }

    function hideLogoutConfirmModal() {
        document.getElementById('logout-confirm-modal').classList.add('hidden');
    }

    document.getElementById('logout-confirm-yes').addEventListener('click', () => {
        document.getElementById('logout-form').submit(); // real logout, POST + @csrf
    });

    document.getElementById('logout-confirm-no').addEventListener('click', hideLogoutConfirmModal);
})();