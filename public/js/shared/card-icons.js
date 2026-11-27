(() => {
    const iconNames = new Set([
        'calendar',
        'calendar-check',
        'calendar-x',
        'clock',
        'court',
        'peso-coin',
        'wallet',
        'trending-up',
        'bar-chart',
        'pie',
        'gauge',
        'people',
        'user-badge',
        'shield',
        'padel',
        'bag',
        'receipt',
        'database',
        'alert',
        'hourglass',
    ]);
    const svgNamespace = 'http://www.w3.org/2000/svg';

    function create(name) {
        if (!iconNames.has(name)) {
            throw new TypeError(`Unknown card icon: ${name}`);
        }

        const chip = document.createElement('span');
        chip.className = 'icon-chip';
        chip.dataset.icon = name;

        const svg = document.createElementNS(svgNamespace, 'svg');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        const use = document.createElementNS(svgNamespace, 'use');
        use.setAttribute('href', `#ci-${name}`);
        svg.append(use);
        chip.append(svg);

        return chip;
    }

    window.SideHouseCardIcons = Object.freeze({ create });
})();
