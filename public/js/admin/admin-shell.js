(function () {
    const key = 'sidehouse-admin-sidebar-collapsed';
    const root = document.documentElement;

    function readCollapsed() {
        try {
            return localStorage.getItem(key) === 'true';
        } catch (_) {
            return false;
        }
    }

    function applyCollapsed(collapsed) {
        const desktop = window.matchMedia('(min-width: 801px)').matches;
        root.classList.toggle('is-admin-sidebar-collapsed', desktop && collapsed);
    }

    let collapsed = readCollapsed();
    applyCollapsed(collapsed);

    document.addEventListener('DOMContentLoaded', function () {
        const button = document.getElementById('adminSidebarCollapseToggle');
        if (!button) return;

        const collapseIcon = button.querySelector('[data-admin-sidebar-icon="collapse"]');
        const expandIcon = button.querySelector('[data-admin-sidebar-icon="expand"]');

        function syncButton() {
            const desktop = window.matchMedia('(min-width: 801px)').matches;
            const isCollapsed = desktop && collapsed;
            button.setAttribute('aria-expanded', String(!isCollapsed));
            button.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
            button.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
            collapseIcon.hidden = isCollapsed;
            expandIcon.hidden = !isCollapsed;
        }

        button.addEventListener('click', function () {
            if (!window.matchMedia('(min-width: 801px)').matches) return;
            collapsed = !collapsed;
            applyCollapsed(collapsed);
            try {
                localStorage.setItem(key, String(collapsed));
            } catch (_) {}
            syncButton();
        });

        window.addEventListener('resize', function () {
            applyCollapsed(collapsed);
            syncButton();
        });

        syncButton();
    });
})();
