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

    function syncButtonClip(container) {
        const iframe = container.querySelector('iframe');
        if (!iframe) return;

        const style = global.getComputedStyle(iframe);
        const height = iframe.getBoundingClientRect().height
            + (Number.parseFloat(style.marginTop) || 0)
            + (Number.parseFloat(style.marginBottom) || 0);
        if (!Number.isFinite(height) || height <= 0) return;

        container.style.setProperty('--google-button-height', `${height}px`);
        container.style.setProperty('--google-button-radius', `${height / 2}px`);
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
        let buttonObserver = null;

        function stop() {
            if (resizeTimer) global.clearTimeout(resizeTimer);
            resizeObserver?.disconnect();
            themeObserver?.disconnect();
            buttonObserver?.disconnect();
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
            syncButtonClip(container);
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

            buttonObserver = new global.MutationObserver(() => syncButtonClip(container));
            buttonObserver.observe(container, { childList: true, subtree: true });
        }

        global.addEventListener('resize', schedule, { passive: true });
        global.addEventListener('orientationchange', schedule);
        renderers.set(container, { dispose: stop });
        container.style.colorScheme = currentScheme();
        update();
    }

    global.GoogleButtonRenderer = { dispose, render };
})(window);
