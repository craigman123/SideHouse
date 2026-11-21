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

    const typeSelect = document.getElementById('courtBlockType');
    if (typeSelect) {
        typeSelect.querySelectorAll('[data-event-permission]').forEach((option) => {
            option.hidden = permissions[option.dataset.eventPermission] !== true;
        });
        const walkInOption = typeSelect.querySelector('option[value="walk_in"]');
        if (walkInOption) walkInOption.hidden = permissions['bookings.create'] !== true;
        if (permissions['calendar_events.manage'] !== true && permissions['bookings.create'] === true) {
            typeSelect.value = 'walk_in';
        }
    }
})();
