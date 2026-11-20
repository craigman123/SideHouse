(() => {
    const shell = document.documentElement;
    const sidebar = document.getElementById('userSidebar');
    const menuButton = document.querySelector('[data-user-mobile-menu-toggle]');
    const backdrop = document.querySelector('[data-user-sidebar-backdrop]');
    const main = document.querySelector('.user-layout-shell .main');
    const mobileQuery = window.matchMedia('(max-width: 800px)');

    if (!sidebar || !menuButton || !backdrop || !main) return;

    let open = false;
    let previousOverflowY = '';

    const setIcons = (isOpen) => {
        menuButton.querySelector('[data-user-menu-icon="open"]')?.toggleAttribute('hidden', isOpen);
        menuButton.querySelector('[data-user-menu-icon="close"]')?.toggleAttribute('hidden', !isOpen);
    };

    const syncMobileState = () => {
        if (!mobileQuery.matches) {
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
        if (updateState) syncMobileState();
        if (returnFocus && mobileQuery.matches) menuButton.focus();
    };

    const openDrawer = () => {
        if (!mobileQuery.matches || open) return;
        open = true;
        previousOverflowY = main.style.overflowY;
        main.style.overflowY = 'hidden';
        shell.classList.add('is-user-mobile-drawer-open');
        syncMobileState();
        sidebar.querySelector('.sidebar-nav a[href]')?.focus();
    };

    menuButton.addEventListener('click', () => open ? closeDrawer() : openDrawer());
    backdrop.addEventListener('click', () => closeDrawer());
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) closeDrawer();
    });
    sidebar.addEventListener('click', (event) => {
        if (event.target.closest('a[href]')) closeDrawer(false);
    });
    mobileQuery.addEventListener('change', syncMobileState);

    syncMobileState();
})();
