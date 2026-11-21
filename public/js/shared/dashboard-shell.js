(function () {
    const root = document.documentElement;
    const key = `sidehouse-${root.dataset.shellArea || 'admin'}-sidebar-collapsed`;

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
    let wasDesktop = window.matchMedia('(min-width: 801px)').matches;

    document.addEventListener('DOMContentLoaded', function () {
        const button = document.getElementById('adminSidebarCollapseToggle');
        const mobileMenuButton = document.querySelector('[data-admin-mobile-menu-toggle]');
        const backdrop = document.getElementById('adminSidebarBackdrop');
        const sidebar = document.getElementById('adminSidebar');

        if (!button) return;

        const collapseIcon = button.querySelector('[data-admin-sidebar-icon="collapse"]');
        const expandIcon = button.querySelector('[data-admin-sidebar-icon="expand"]');
        const openMenuIcon = mobileMenuButton?.querySelector('[data-admin-menu-icon="open"]');
        const closeMenuIcon = mobileMenuButton?.querySelector('[data-admin-menu-icon="close"]');
        let drawerOpen = false;
        let previousBodyOverflow = '';

        function isDesktop() {
            return window.matchMedia('(min-width: 801px)').matches;
        }

        function syncButton() {
            const desktop = isDesktop();
            const isCollapsed = desktop && collapsed;
            button.setAttribute('aria-expanded', String(!isCollapsed));
            button.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
            button.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
            collapseIcon.hidden = isCollapsed;
            expandIcon.hidden = !isCollapsed;
        }

        function syncMobileMenuButton() {
            if (!mobileMenuButton) return;
            const expanded = !isDesktop() && drawerOpen;
            mobileMenuButton.setAttribute('aria-expanded', String(expanded));
            mobileMenuButton.setAttribute('aria-label', expanded ? 'Close navigation' : 'Open navigation');
            mobileMenuButton.title = expanded ? 'Close navigation' : 'Open navigation';
            if (openMenuIcon) openMenuIcon.hidden = expanded;
            if (closeMenuIcon) closeMenuIcon.hidden = !expanded;
        }

        function setDrawerOpen(open, returnFocus) {
            if (!mobileMenuButton || !backdrop || !sidebar) return;
            if (open && isDesktop()) return;

            const wasOpen = drawerOpen;
            drawerOpen = open;
            root.classList.toggle('is-admin-mobile-drawer-open', drawerOpen);
            backdrop.hidden = !drawerOpen;

            if (drawerOpen) {
                previousBodyOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                sidebar.inert = false;
                sidebar.setAttribute('aria-hidden', 'false');
                syncMobileMenuButton();
                sidebar.querySelector('.sidebar-nav a')?.focus();
                return;
            }

            if (wasOpen) document.body.style.overflow = previousBodyOverflow;
            if (isDesktop()) {
                sidebar.inert = false;
                sidebar.removeAttribute('aria-hidden');
            } else {
                sidebar.inert = true;
                sidebar.setAttribute('aria-hidden', 'true');
            }

            syncMobileMenuButton();
            if (wasOpen && returnFocus && !isDesktop()) mobileMenuButton.focus();
        }

        if (sidebar && !isDesktop()) {
            sidebar.inert = true;
            sidebar.setAttribute('aria-hidden', 'true');
        } else if (sidebar) {
            sidebar.inert = false;
            sidebar.removeAttribute('aria-hidden');
        }

        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', function () {
                if (!isDesktop()) setDrawerOpen(!drawerOpen, true);
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setDrawerOpen(false, true);
            });
        }

        if (sidebar) {
            sidebar.querySelectorAll('a[href]').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (drawerOpen) setDrawerOpen(false, false);
                });
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && drawerOpen) setDrawerOpen(false, true);
        });

        button.addEventListener('click', function () {
            if (!isDesktop()) return;
            collapsed = !collapsed;
            applyCollapsed(collapsed);
            try {
                localStorage.setItem(key, String(collapsed));
            } catch (_) {}
            syncButton();
        });

        window.addEventListener('resize', function () {
            const desktop = isDesktop();
            if (desktop !== wasDesktop) {
                setDrawerOpen(false, false);
                wasDesktop = desktop;
            }
            applyCollapsed(collapsed);
            syncButton();
            syncMobileMenuButton();
        });

        syncButton();
        syncMobileMenuButton();
    });
})();
