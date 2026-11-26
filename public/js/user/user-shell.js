(() => {
    const shell = document.documentElement;
    const sidebar = document.getElementById('userSidebar');
    const menuButton = document.querySelector('[data-user-mobile-menu-toggle]');
    const lumiButton = document.querySelector('[data-user-lumi-button]');
    const backdrop = document.querySelector('[data-user-sidebar-backdrop]');
    const main = document.querySelector('.user-layout-shell .main');
    const drawerQuery = window.matchMedia('(max-width: 1450px)');

    lumiButton?.addEventListener('click', () => {
        const destination = lumiButton.dataset.href;
        if (destination) window.location.assign(destination);
    });

    if (!sidebar || !menuButton || !backdrop || !main) return;

    let open = false;
    let previousOverflowY = '';

    const setIcons = (isOpen) => {
        menuButton.querySelector('[data-user-menu-icon="open"]')?.toggleAttribute('hidden', isOpen);
        menuButton.querySelector('[data-user-menu-icon="close"]')?.toggleAttribute('hidden', !isOpen);
    };

    const syncDrawerState = () => {
        if (!drawerQuery.matches) {
            closeDrawer(false, false);
            sidebar.inert = false;
            sidebar.removeAttribute('aria-hidden');
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', 'Open navigation');
            menuButton.title = 'Open navigation';
            backdrop.hidden = true;
            setIcons(false);
            return;
        }

        sidebar.inert = !open;
        sidebar.setAttribute('aria-hidden', String(!open));
        menuButton.setAttribute('aria-expanded', String(open));
        menuButton.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        menuButton.title = open ? 'Close navigation' : 'Open navigation';
        backdrop.hidden = !open;
        setIcons(open);
    };

    const closeDrawer = (returnFocus = true, updateState = true) => {
        if (!open) return;
        open = false;
        shell.classList.remove('is-user-mobile-drawer-open');
        main.style.overflowY = previousOverflowY;
        previousOverflowY = '';
        if (updateState) syncDrawerState();
        if (returnFocus && drawerQuery.matches) menuButton.focus();
    };

    const openDrawer = () => {
        if (!drawerQuery.matches || open) return;
        open = true;
        previousOverflowY = main.style.overflowY;
        main.style.overflowY = 'hidden';
        shell.classList.add('is-user-mobile-drawer-open');
        syncDrawerState();
        sidebar.querySelector('.sidebar-nav a[href]')?.focus();
    };

    menuButton.addEventListener('click', () => open ? closeDrawer() : openDrawer());
    backdrop.addEventListener('click', () => closeDrawer());
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Tab' && open) {
            const focusable = [...sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter((element) => element.tabIndex >= 0 && element.getClientRects().length > 0);

            if (!focusable.length) {
                event.preventDefault();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        }

        if (event.key === 'Escape' && open) closeDrawer();
    });
    sidebar.addEventListener('click', (event) => {
        if (event.target.closest('a[href]')) closeDrawer();
    });
    drawerQuery.addEventListener('change', syncDrawerState);

    syncDrawerState();
})();
