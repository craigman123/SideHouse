(() => {
    const data = document.getElementById('courtBoardData');
    if (!data) return;

    let permissions;
    try {
        permissions = JSON.parse(data.textContent || '{}').permissions || {};
    } catch (_) {
        permissions = {};
    }

    document.querySelectorAll('[data-permission]').forEach((control) => {
        const allowed = permissions[control.dataset.permission] === true;
        control.hidden = !allowed;
        if ('disabled' in control) control.disabled = !allowed;
    });

    document.querySelectorAll('[data-event-permission]').forEach((option) => {
        const allowed = option.value === 'open_play'
            ? permissions['calendar_events.open_play'] === true
            : permissions['calendar_events.manage'] === true;
        option.hidden = !allowed;
        option.disabled = !allowed;
    });

    const typeSelect = document.getElementById('courtBlockType');
    if (typeSelect) {
        const walkInOption = typeSelect.querySelector('option[value="walk_in"]');
        if (walkInOption) walkInOption.hidden = permissions['bookings.create'] !== true;
        if (permissions['calendar_events.manage'] !== true && permissions['calendar_events.open_play'] === true) typeSelect.value = 'open_play';
    }
})();
