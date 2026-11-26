(function (global) {
    const renderers = new WeakMap();

    function currentScheme() {
        return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
    }

    function dispose(container) {
        const renderer = renderers.get(container);
        if (!renderer) return;
        renderer.dispose();
        renderers.delete(container);
    }

    function render(container, draw, options) {
        dispose(container);
        container.classList.add('google-signin-responsive');

        let lastWidth = 0;
        let lastScheme = '';
        let rendered = false;
        let resizeTimer = 0;
        let resizeObserver = null;
        let themeObserver = null;

        function stop() {
            if (resizeTimer) global.clearTimeout(resizeTimer);
            resizeObserver?.disconnect();
            themeObserver?.disconnect();
            global.removeEventListener('resize', schedule);
            global.removeEventListener('orientationchange', schedule);
        }

        function update() {
            resizeTimer = 0;
            if (options?.isActive && !options.isActive()) {
                dispose(container);
                return;
            }

            const scheme = currentScheme();
            container.style.colorScheme = scheme;
            if (!container.isConnected || container.hidden || !container.getClientRects().length) return;

            const measuredWidth = Math.floor(container.getBoundingClientRect().width);
            if (measuredWidth <= 0) return;

            const width = Math.max(200, Math.min(400, measuredWidth));
            if (rendered && width === lastWidth && scheme === lastScheme) return;

            const isFirstRender = !rendered;
            lastWidth = width;
            lastScheme = scheme;
            rendered = true;
            container.replaceChildren();
            draw(width, scheme);
            if (isFirstRender) options?.onReady?.();
        }

        function schedule() {
            if (resizeTimer) global.clearTimeout(resizeTimer);
            resizeTimer = global.setTimeout(update, 100);
        }

        if (global.ResizeObserver) {
            resizeObserver = new global.ResizeObserver(schedule);
            resizeObserver.observe(container);
        }

        if (global.MutationObserver) {
            themeObserver = new global.MutationObserver(schedule);
            themeObserver.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-theme'],
            });
        }

        global.addEventListener('resize', schedule, { passive: true });
        global.addEventListener('orientationchange', schedule);
        renderers.set(container, { dispose: stop });
        container.style.colorScheme = currentScheme();
        update();
    }

    global.GoogleButtonRenderer = { dispose, render };
})(window);
