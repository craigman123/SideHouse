(function () {
    const key = 'sidehouse-guest-theme';
    let theme = 'dark';

    try {
        const saved = localStorage.getItem(key);
        if (saved === 'light' || saved === 'dark') theme = saved;
    } catch (_) {}

    document.documentElement.dataset.theme = theme;

    function syncButton(button) {
        const light = document.documentElement.dataset.theme === 'light';
        button.setAttribute('aria-label', light ? 'Switch to dark mode' : 'Switch to light mode');
        button.setAttribute('aria-pressed', String(light));
        button.querySelector('[data-theme-icon="sun"]').hidden = light;
        button.querySelector('[data-theme-icon="moon"]').hidden = !light;
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            syncButton(button);
            button.addEventListener('click', function () {
                const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
                document.documentElement.dataset.theme = next;
                try { localStorage.setItem(key, next); } catch (_) {}
                syncButton(button);
            });
        });
    });
})();
