(function () {
    const key = 'sidehouse-user-sidebar-collapsed';
    const root = document.documentElement;

    function readSavedState() {
        try {
            return localStorage.getItem(key) === 'true';
        } catch (_) {
            return false;
        }
    }

    function isDesktop() {
        return window.innerWidth > 1450;
    }

    function applySavedState(savedState) {
        root.classList.toggle('is-sidebar-collapsed', isDesktop() && savedState);
    }

    let savedState = readSavedState();
    applySavedState(savedState);

    function initializeButton() {
        const button = document.querySelector('.user-layout-shell .sidebar-collapse-toggle');
        if (!button || !document.getElementById(button.getAttribute('aria-controls'))) return;

        const collapseIcon = button.querySelector('[data-sidebar-icon="collapse"]');
        const expandIcon = button.querySelector('[data-sidebar-icon="expand"]');

        function syncButton() {
            const collapsed = isDesktop() && savedState;
            applySavedState(savedState);
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            button.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            if (collapseIcon) collapseIcon.hidden = collapsed;
            if (expandIcon) expandIcon.hidden = !collapsed;
        }

        syncButton();

        button.addEventListener('click', function () {
            if (!isDesktop()) return;
            savedState = !root.classList.contains('is-sidebar-collapsed');
            syncButton();
            try {
                localStorage.setItem(key, String(savedState));
            } catch (_) {}
        });

        window.addEventListener('resize', function () {
            savedState = readSavedState();
            syncButton();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeButton, { once: true });
    } else {
        initializeButton();
    }
})();
