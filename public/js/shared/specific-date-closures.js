(() => {
    const widgets = [...document.querySelectorAll('[data-specific-closures]')];
    if (!widgets.length) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const escapeText = (value) => String(value ?? '');

    widgets.forEach((widget) => {
        const form = widget.querySelector('[data-closure-form]');
        const list = widget.querySelector('[data-closure-list]');
        const message = widget.querySelector('[data-closure-message]');
        const preview = widget.querySelector('[data-closure-preview]');
        const conflicts = widget.querySelector('[data-closure-conflicts]');
        const startDate = form.elements.start_date;
        const endDate = form.elements.end_date;
        const court = form.elements.court_id;
        const startTime = form.elements.start_time;
        const endTime = form.elements.end_time;
        let editingId = null;
        let days = [];
        const api = async (url, options = {}) => {
            const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(options.headers || {}) } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) { const error = new Error(data.message || 'The request could not be completed.'); error.data = data; error.status = response.status; throw error; }
            return data;
        };
        const showMessage = (text, isError = false) => { message.textContent = text; message.classList.toggle('is-error', isError); };
        const minuteLabel = (minutes) => {
            const date = new Date(2000, 0, 1, Math.floor(minutes / 60) % 24, minutes % 60);
            return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        };
        const updatePreview = () => {
            const selected = court.selectedOptions[0]?.textContent || 'All courts';
            const date = startDate.value ? new Date(`${startDate.value}T00:00:00`).toLocaleDateString([], { month: 'short', day: 'numeric' }) : 'the selected date';
            preview.textContent = startTime.value && endTime.value ? `${selected} closed ${date}, ${startTime.selectedOptions[0]?.textContent || ''} to ${endTime.selectedOptions[0]?.textContent || ''}` : '';
        };
        const buildTimes = () => {
            const previousStart = startTime.value; const previousEnd = endTime.value;
            startTime.replaceChildren(); endTime.replaceChildren();
            const windows = days.filter((day) => !day.closed_weekday).map((day) => ({ open: Number(day.open_minutes), close: Number(day.open_minutes) + Number(day.close_offset) }));
            if (!windows.length) { showMessage('The selected date range includes a weekly closure or has no operating hours.', true); return; }
            const open = Math.max(...windows.map((item) => item.open)); const close = Math.min(...windows.map((item) => item.close));
            const step = Number(widget.dataset.step || 60);
            const add = (select, value, label) => { const option = document.createElement('option'); option.value = value; option.textContent = label; select.append(option); };
            for (let minute = open; minute < close; minute += step) add(startTime, `${String(Math.floor(minute / 60) % 24).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`, minuteLabel(minute));
            for (let minute = open + step; minute <= close; minute += step) add(endTime, `${String(Math.floor(minute / 60) % 24).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`, minuteLabel(minute));
            if ([...startTime.options].some((option) => option.value === previousStart)) startTime.value = previousStart;
            if ([...endTime.options].some((option) => option.value === previousEnd)) endTime.value = previousEnd;
            updatePreview();
        };
        const refreshHours = async () => {
            if (!startDate.value || !endDate.value) return;
            showMessage('Loading operating hours…');
            try {
                const params = new URLSearchParams({ start_date: startDate.value, end_date: endDate.value, court_id: court.value });
                const data = await api(`/admin/specific-closures/operating-hours?${params}`);
                widget.dataset.step = String(data.step_minutes);
                days = data.days.map((day) => ({ ...day, open_minutes: data.open_minutes }));
                buildTimes();
                showMessage('');
            } catch (error) { showMessage(error.message, true); }
        };
        const renderList = (closures) => {
            list.replaceChildren();
            if (!closures.length) { const empty = document.createElement('p'); empty.className = 'specific-closure-empty'; empty.textContent = 'No upcoming closures'; list.append(empty); return; }
            closures.forEach((item) => {
                const row = document.createElement('article'); row.className = 'specific-closure-row';
                const details = document.createElement('p'); details.textContent = `${item.date} · ${item.start_time}–${item.end_time} · ${item.court}${item.reason ? ` · ${item.reason}` : ''} · by ${item.created_by}`;
                const edit = document.createElement('button'); edit.type = 'button'; edit.textContent = 'Edit'; edit.className = 'court-block-secondary';
                edit.addEventListener('click', async () => {
                    const courtOptions = [...court.options].slice(1).map((option) => `${option.textContent} (ID ${option.value})`).join(', ');
                    const values = {
                        date: window.prompt('Closure date (YYYY-MM-DD)', item.date),
                        start: window.prompt('Start time (HH:MM)', item.start_time),
                        end: window.prompt('End time (HH:MM)', item.end_time),
                        courtId: window.prompt(`Court ID, or blank for all courts. Available: ${courtOptions}`, item.court_id ?? ''),
                        reason: window.prompt('Reason / note', item.reason || ''),
                    };
                    if (Object.values(values).some((value) => value === null)) return;
                    try {
                        const result = await api(`/admin/specific-closures/${item.id}`, { method: 'PATCH', body: JSON.stringify({ date: values.date, start_time: values.start, end_time: values.end, court_id: values.courtId || null, reason: values.reason }) });
                        renderList(result.closures); showMessage(result.message);
                        document.dispatchEvent(new CustomEvent('specific-closures:updated'));
                    }
                    catch (error) { showMessage(error.data?.message || error.message, true); renderConflicts(error.data?.conflicts || []); }
                });
                const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove'; remove.className = 'court-block-secondary';
                remove.addEventListener('click', async () => {
                    if (!window.confirm('Remove this court closure?')) return;
                    try {
                        const result = await api(`/admin/specific-closures/${item.id}`, { method: 'DELETE' });
                        renderList(result.closures); showMessage(result.message);
                        document.dispatchEvent(new CustomEvent('specific-closures:updated'));
                    } catch (error) { showMessage(error.message, true); }
                });
                row.append(details, edit, remove); list.append(row);
            });
        };
        const renderConflicts = (items) => {
            conflicts.replaceChildren(); conflicts.hidden = !items.length;
            items.forEach((item) => { const row = document.createElement('p'); row.textContent = `${item.customer} · ${item.court} · ${item.date} · ${item.start_time}–${item.end_time} · ${item.status}`; conflicts.append(row); });
        };
        const loadList = async () => { try { const data = await api('/admin/specific-closures'); renderList(data.closures || []); } catch (error) { list.textContent = error.message; } };
        [startDate, endDate, court].forEach((input) => input.addEventListener('change', refreshHours));
        [startTime, endTime, court, startDate].forEach((input) => input.addEventListener('change', updatePreview));
        form.addEventListener('submit', async (event) => {
            event.preventDefault(); renderConflicts([]); showMessage('Saving…');
            try {
                const result = await api('/admin/specific-closures', { method: 'POST', body: JSON.stringify({ start_date: startDate.value, end_date: endDate.value, start_time: startTime.value, end_time: endTime.value, court_id: court.value || null, reason: form.elements.reason.value }) });
                renderList(result.closures || []); showMessage(result.message); form.elements.reason.value = '';
                document.dispatchEvent(new CustomEvent('specific-closures:updated'));
            } catch (error) { showMessage(error.message, true); renderConflicts(error.data?.conflicts || []); }
        });
        loadList(); refreshHours();
    });
})();
