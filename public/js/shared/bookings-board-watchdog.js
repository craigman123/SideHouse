(() => {
    const workspace = document.getElementById('bookingsWorkspace');
    if (!workspace) return;

    const panelIds = {
        board: 'courtBoardView',
        month: 'courtMonthView',
        week: 'courtWeekView',
    };
    const activeView = () => Object.keys(panelIds).find((view) => {
        const panel = document.getElementById(panelIds[view]);
        return panel && !panel.hidden;
    });
    const showLoadError = () => {
        if (workspace.dataset.calendarReady === 'true') return;
        const view = activeView();
        if (!view) return;

        const panel = document.getElementById(panelIds[view]);
        const container = panel.querySelector(`[data-calendar-loader="${view}"]`);
        const skeleton = panel.querySelector(`[data-calendar-skeleton="${view}"]`);
        const label = container?.querySelector(`[data-calendar-loading-label="${view}"]`);
        const status = container?.querySelector(`[data-calendar-loading-status="${view}"]`);
        const errorState = workspace.querySelector(`[data-calendar-error-state="${view}"]`);
        if (!container || !skeleton || !errorState || !errorState.hidden) return;

        console.error(`[bookings] ${view} calendar did not render before the startup timeout`);
        skeleton.hidden = true;
        if (label) label.hidden = true;
        if (status) status.textContent = '';
        container.removeAttribute('aria-busy');
        const message = errorState.querySelector('[data-calendar-error-message]');
        if (message) message.textContent = 'Calendar could not load. Retry to reload the page.';
        errorState.hidden = false;
    };

    workspace.addEventListener('click', (event) => {
        const retry = event.target?.closest?.('[data-calendar-retry]');
        if (!retry || workspace.dataset.calendarReady === 'true') return;
        const errorState = retry.closest('.court-calendar-error-state');
        if (!errorState || errorState.hidden) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.location.reload();
    }, true);

    window.setTimeout(showLoadError, 10000);
})();
