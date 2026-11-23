(() => {
    const panel = document.querySelector('[data-switch-access]');
    if (!panel) return;

    document.addEventListener('click', (event) => {
        if (panel.open && !panel.contains(event.target)) panel.open = false;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !panel.open) return;
        panel.open = false;
        panel.querySelector('summary')?.focus();
    });
})();
