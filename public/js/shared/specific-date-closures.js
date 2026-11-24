(() => {
    const widgets = [...document.querySelectorAll('[data-specific-closures]')];
    if (!widgets.length) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const to12h = (value) => {
    const [h, m] = String(value).split(':').map(Number);
    return new Date(2000, 0, 1, h, m).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
};

const makeDots = () => {
    const wrap = document.createElement('span');
    wrap.className = 'closure-dots';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.append(document.createElement('span'), document.createElement('span'), document.createElement('span'));
    return wrap;
};

const toast = (text, type = 'success') => {
    let stack = document.querySelector('.closure-toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'closure-toast-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.append(stack);
    }
    const item = document.createElement('div');
    item.className = `closure-toast closure-toast--${type}`;
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const label = document.createElement('span');
    label.textContent = text;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'closure-toast-close btn-ghost-danger';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    item.append(label, close);
    stack.append(item);
    let timer;
    const remove = () => { clearTimeout(timer); item.remove(); };
    const start = () => { timer = setTimeout(remove, type === 'error' ? 7000 : 4500); };
    close.addEventListener('click', remove);
    item.addEventListener('mouseenter', () => clearTimeout(timer));
    item.addEventListener('mouseleave', start);
    start();
};

const confirmRemove = (rule) => new Promise((resolve) => {
    const dialog = document.createElement('dialog');
    dialog.className = 'closure-dialog';
    const body = document.createElement('div');
    body.className = 'closure-dialog-body';
    const title = document.createElement('h3');
    title.textContent = 'Remove weekly closure?';
    const text = document.createElement('p');
    text.textContent = `${rule.weekday_label}, ${to12h(rule.start_time)} – ${to12h(rule.end_time)}, ${rule.court}. These times will be open for booking again.`;
    const actions = document.createElement('div');
    actions.className = 'closure-dialog-actions';
    const cancel = document.createElement('button');
    cancel.type = 'button'; cancel.className = 'closure-btn btn-ghost-danger'; cancel.textContent = 'Cancel';
    const ok = document.createElement('button');
    ok.type = 'button'; ok.className = 'closure-btn closure-btn--danger'; ok.textContent = 'Remove closure';
    actions.append(cancel, ok);
    body.append(title, text, actions);
    dialog.append(body);
    document.body.append(dialog);
    const done = (value) => { dialog.close(); dialog.remove(); resolve(value); };
    cancel.addEventListener('click', () => done(false));
    ok.addEventListener('click', () => done(true));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); done(false); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) done(false); });
    dialog.showModal();
    cancel.focus();
});

    widgets.forEach((widget) => {
        const form = widget.querySelector('[data-closure-form]');
        const list = widget.querySelector('[data-closure-list]');
        const message = widget.querySelector('[data-closure-message]');
        const hoursNote = widget.querySelector('[data-closure-hours-note]');
        const preview = widget.querySelector('[data-closure-preview]');
        const warnings = widget.querySelector('[data-closure-conflicts]');
        const dayInputs = [...form.querySelectorAll('input[name="weekdays[]"]')];
        const court = form.elements.court_id;
        const startTime = form.elements.start_time;
        const endTime = form.elements.end_time;
        let weekdayHours = [];
        let hoursRequest = 0;
        const addButton = form.querySelector('[type="submit"]');

        const lockTargets = [
            widget.querySelector('.specific-closure-quick-select'),
            widget.querySelector('.specific-closure-day-chips'),
            widget.querySelector('.specific-closure-fields'),
            widget.querySelector('.specific-closure-form-actions'),
            list,
        ].filter(Boolean);

        const setLoading = (on) => {
            widget.classList.toggle('is-loading', on);
            widget.setAttribute('aria-busy', String(on));
            lockTargets.forEach((node) => { node.inert = on; });
            if (on) {
                const dots = document.createElement('span');
                dots.className = 'closure-dots';
                dots.setAttribute('aria-hidden', 'true');
                dots.append(document.createElement('span'), document.createElement('span'), document.createElement('span'));
                hoursNote.replaceChildren('Loading operating hours', dots);
                hoursNote.hidden = false;
            }
        };

        const setBusy = (on) => {
            widget.classList.toggle('is-loading', on);
            widget.setAttribute('aria-busy', String(on));
            lockTargets.forEach((node) => { node.inert = on; });
        };

        const setSaving = (on) => {
            setBusy(on);
            addButton.disabled = on;
            if (on) addButton.replaceChildren('Saving', makeDots());
            else addButton.textContent = editingRule ? 'Save changes' : submitLabel;
        };

        let editingRule = null;
        const submitLabel = addButton.textContent;
        const formActions = form.querySelector('.specific-closure-form-actions') || form;
        const editBanner = document.createElement('p');
        editBanner.className = 'specific-closure-edit-banner';
        editBanner.hidden = true;
        formActions.before(editBanner);
        const cancelEdit = document.createElement('button');
        cancelEdit.type = 'button';
        cancelEdit.className = 'closure-btn btn-ghost-danger';
        cancelEdit.textContent = 'Cancel edit';
        cancelEdit.hidden = true;
        formActions.prepend(cancelEdit);
        cancelEdit.addEventListener('click', () => form.reset());
        const leaveEditState = () => {
            editingRule = null;
            addButton.textContent = submitLabel;
            cancelEdit.hidden = true;
            editBanner.hidden = true;
            widget.querySelectorAll('[data-weekday-preset]').forEach((button) => { button.disabled = false; });
            list.querySelectorAll('.is-editing').forEach((row) => row.classList.remove('is-editing'));
        };

        const api = async (url, options = {}) => {
            const response = await fetch(url, {
                ...options,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf,
                    ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(options.headers || {}) },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const error = new Error(data.message || 'The request could not be completed.');
                error.data = data;
                error.status = response.status;
                throw error;
            }
            return data;
        };
        const selectedDays = () => dayInputs.filter((input) => input.checked).map((input) => Number(input.value));
        const showMessage = (text, isError = false) => {
            message.hidden = true;
            message.textContent = '';
            if (text && text !== 'Saving…') toast(text, isError ? 'error' : 'success');
        };
        const setHoursNote = (text) => { hoursNote.textContent = text; hoursNote.hidden = !text; };
        const minuteLabel = (minutes) => new Date(2000, 0, 1, Math.floor(minutes / 60) % 24, minutes % 60)
            .toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        const clockValue = (minutes) => `${String(Math.floor(minutes / 60) % 24).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

        const updatePreview = () => {
        const names = selectedDays().map((day) => weekdayNames[day].slice(0, 3));
        const courtName = court.selectedOptions[0]?.textContent || 'All courts';
        const start = startTime.value ? (startTime.selectedOptions[0]?.textContent || '') : '';
        const end = endTime.value ? (endTime.selectedOptions[0]?.textContent || '') : '';
        const dayList = names.length === 7
            ? 'day'
            : new Intl.ListFormat('en', { style: 'long', type: 'conjunction' }).format(names);
        preview.textContent = names.length && start && end
            ? `${courtName} closed every ${dayList}, ${start} to ${end}`
            : '';
        preview.hidden = !preview.textContent;
    };

        const lockTimes = (label) => {
            startTime.replaceChildren(new Option(label, ''));
            endTime.replaceChildren(new Option(label, ''));
            startTime.disabled = true;
            endTime.disabled = true;
            addButton.disabled = true;
        };

        const buildTimeOptions = () => {
            const picked = selectedDays();
            const days = picked.map((weekday) => weekdayHours.find((day) => Number(day.weekday) === weekday)).filter(Boolean);
            const openDays = days.filter((day) => !day.closed_all_day);
            setHoursNote('');
            if (!picked.length) {
                lockTimes('Select a day first');
                setHoursNote('Select at least one weekday to see its operating hours.');
                updatePreview();
                return;
            }
            const allDayClosed = days.filter((day) => day.closed_all_day).map((day) => weekdayNames[day.weekday]);
            if (allDayClosed.length) {
                setHoursNote(`${allDayClosed.join(', ')} ${allDayClosed.length === 1 ? 'is' : 'are'} closed all day in Configuration.`);
                dayInputs.forEach((input) => {
                    if (allDayClosed.includes(weekdayNames[Number(input.value)])) input.checked = false;
                });
            }
            if (!openDays.length) { lockTimes('Select a day first'); updatePreview(); return; }
            const open = Math.max(...openDays.map((day) => Number(day.open_minutes)));
            const close = Math.min(...openDays.map((day) => Number(day.close_offset)));
            if (close <= open) {
                const problems = openDays.filter((day) => Number(day.close_offset) <= open || Number(day.open_minutes) >= close)
                    .map((day) => weekdayNames[day.weekday]);
                lockTimes('No shared hours');
                setHoursNote(`No shared operating time for the selected days${problems.length ? `; check ${problems.join(', ')}` : ''}.`);
                updatePreview();
                return;
            }
            const first = openDays[0];
            const hoursDiffer = openDays.some((day) => Number(day.open_minutes) !== Number(first.open_minutes)
                || Number(day.close_offset) !== Number(first.close_offset));
            if (hoursDiffer) setHoursNote(`Selected weekdays have different hours; times are limited to their shared operating window (${minuteLabel(open)}–${minuteLabel(close)}).`);
            else if (!allDayClosed.length) setHoursNote(`Times follow the configured ${minuteLabel(open)}–${minuteLabel(close)} operating hours.`);

            startTime.disabled = false;
            endTime.disabled = false;
            startTime.replaceChildren();
            endTime.replaceChildren();
            const step = Number(widget.dataset.step || 60);
            const add = (select, minute) => {
                const option = document.createElement('option');
                option.value = clockValue(minute);
                option.textContent = minuteLabel(minute);
                option.dataset.minute = String(minute);
                select.append(option);
            };
            for (let minute = open; minute < close; minute += step) add(startTime, minute);
            for (let minute = open + step; minute <= close; minute += step) add(endTime, minute);
            const endOptions = [...endTime.options].map((option) => ({ value: option.value, label: option.textContent, minute: Number(option.dataset.minute) }));
            const filterEndTimes = () => {
                const previous = endTime.value;
                const selectedStart = Number(startTime.selectedOptions[0]?.dataset.minute);
                endTime.replaceChildren();
                endOptions.filter((option) => option.minute > selectedStart).forEach((option) => {
                    const node = document.createElement('option');
                    node.value = option.value;
                    node.textContent = option.label;
                    node.dataset.minute = String(option.minute);
                    endTime.append(node);
                });
                if ([...endTime.options].some((option) => option.value === previous)) endTime.value = previous;
                else if (endTime.options.length) endTime.selectedIndex = 0;
                addButton.disabled = !startTime.value || !endTime.value || !selectedDays().length;
                updatePreview();
            };
            startTime.onchange = filterEndTimes;
            filterEndTimes();
            updatePreview();
        };

        const refreshHours = async () => {
            const requestId = ++hoursRequest;
            startTime.replaceChildren(new Option('Loading…', ''));
            endTime.replaceChildren(new Option('Loading…', ''));
            startTime.disabled = true;
            endTime.disabled = true;
            addButton.disabled = true;
            setLoading(true);
            updatePreview();
            const params = new URLSearchParams();
            selectedDays().forEach((day) => params.append('weekdays[]', String(day)));
            if (court.value) params.set('court_id', court.value);
            try {
                const data = await api(`/admin/specific-closures/operating-hours?${params}`);
                if (requestId !== hoursRequest) return;
                widget.dataset.step = String(data.step_minutes);
                weekdayHours = data.weekdays || [];
                weekdayHours.forEach((day) => {
                    const input = dayInputs.find((candidate) => Number(candidate.value) === Number(day.weekday));
                    if (!input) return;
                    input.disabled = Boolean(day.closed_all_day);
                    const chip = input.closest('.specific-closure-day-chip');
                    chip?.classList.toggle('is-disabled', input.disabled);
                    let hint = chip?.querySelector('small');
                    if (input.disabled && !hint) {
                        hint = document.createElement('small');
                        hint.textContent = 'Closed all day';
                        chip.append(hint);
                    } else if (!input.disabled && hint) {
                        hint.remove();
                    }
                });
                buildTimeOptions();
            } catch (error) {
                if (requestId === hoursRequest) {
                    lockTimes('Unavailable');
                    setHoursNote(`Could not load operating hours: ${error.message}`);
                    showMessage(error.message, true);
                }
            } finally {
                if (requestId === hoursRequest) setLoading(false);
            }
        };

        const renderWarnings = (items) => {
            warnings.replaceChildren();
            warnings.hidden = !items.length;
            if (!items.length) return;
            const heading = document.createElement('strong');
            heading.textContent = `Conflicts with ${items.length} bookings in the next 60 days. Reschedule them as needed; none were changed.`;
            warnings.append(heading);
            items.forEach((item) => {
                const line = document.createElement('p');
                line.textContent = `${item.customer} · ${item.court} · ${item.date} · ${item.start_time}–${item.end_time} · ${item.status}`;
                warnings.append(line);
            });
        };

        const enterEdit = async (rule) => {
            editingRule = rule;
            renderWarnings([]);
            dayInputs.forEach((input) => { input.checked = Number(input.value) === Number(rule.weekday); });
            court.value = rule.court_id == null ? '' : String(rule.court_id);
            form.elements.reason.value = rule.reason || '';
            addButton.textContent = 'Save changes';
            cancelEdit.hidden = false;
            widget.querySelectorAll('[data-weekday-preset]').forEach((button) => { button.disabled = true; });
            editBanner.textContent = `Editing weekly closure: ${rule.weekday_label}, ${to12h(rule.start_time)} – ${to12h(rule.end_time)}, ${rule.court}`;
            editBanner.hidden = false;
            list.querySelectorAll('.specific-closure-row').forEach((row) => row.classList.toggle('is-editing', row.dataset.ruleId === String(rule.id)));
            await refreshHours();
            const wantedStart = String(rule.start_time).slice(0, 5);
            const wantedEnd = String(rule.end_time).slice(0, 5);
            startTime.value = wantedStart;
            startTime.dispatchEvent(new Event('change'));
            endTime.value = wantedEnd;
            updatePreview();
            if (startTime.value !== wantedStart || endTime.value !== wantedEnd) {
                toast('The saved time is outside the current operating hours. Pick new times.', 'error');
            }
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            startTime.focus();
        };

        const renderList = (rules) => {
            list.replaceChildren();
            if (!rules.length) {
                const empty = document.createElement('p');
                empty.className = 'specific-closure-empty';
                empty.textContent = 'No weekly closures yet';
                list.append(empty);
                return;
            }
            rules.forEach((rule) => {
                const timeRange = `${to12h(rule.start_time)} – ${to12h(rule.end_time)}`;
                const row = document.createElement('article');
                row.className = 'specific-closure-row';
                row.dataset.ruleId = String(rule.id);
                if (editingRule && Number(editingRule.id) === Number(rule.id)) row.classList.add('is-editing');
                const description = document.createElement('div');
                description.className = 'specific-closure-description';
                const chip = document.createElement('span');
                chip.className = 'specific-closure-day-badge';
                chip.textContent = rule.weekday_label;
                const detail = document.createElement('p');
                detail.textContent = `${timeRange} · ${rule.court}${rule.reason ? ` · ${rule.reason}` : ''} · by ${rule.created_by}`;
                description.append(chip, detail);
                if (rule.conflict_count) {
                    const badge = document.createElement('span');
                    badge.className = 'specific-closure-conflict-badge';
                    badge.textContent = `Conflicts with ${rule.conflict_count} bookings`;
                    description.append(badge);
                }
                const actions = document.createElement('div');
                actions.className = 'specific-closure-row-actions';
                const edit = document.createElement('button');
                edit.type = 'button'; edit.className = 'closure-btn'; edit.textContent = 'Edit';
                edit.setAttribute('aria-label', `Edit weekly closure ${rule.weekday_label}, ${timeRange}`);
                edit.addEventListener('click', () => enterEdit(rule));
                const remove = document.createElement('button');
                remove.type = 'button'; remove.className = 'closure-btn closure-btn--danger btn-ghost-danger'; remove.textContent = 'Remove';
                remove.setAttribute('aria-label', `Remove weekly closure ${rule.weekday_label}, ${timeRange}`);
                remove.addEventListener('click', async () => {
                    if (!(await confirmRemove(rule))) return;
                    setBusy(true);
                    remove.replaceChildren('Removing', makeDots());
                    try {
                        const result = await api(`/admin/specific-closures/${rule.id}`, { method: 'DELETE' });
                        if (editingRule && Number(editingRule.id) === Number(rule.id)) form.reset();
                        renderList(result.closures || []);
                        showMessage(result.message);
                        document.dispatchEvent(new CustomEvent('specific-closures:updated'));
                    } catch (error) {
                        remove.textContent = 'Remove';
                        showMessage(error.message, true);
                    } finally {
                        setBusy(false);
                    }
                });
                actions.append(edit, remove);
                row.append(description, actions);
                list.append(row);
            });
        };

        const loadList = async () => {
            try { const data = await api('/admin/specific-closures'); renderList(data.closures || []); }
            catch (error) { list.textContent = error.message; }
        };
        dayInputs.forEach((input) => input.addEventListener('change', () => {
            if (editingRule && input.checked) dayInputs.forEach((other) => { if (other !== input) other.checked = false; });
            renderWarnings([]);
            refreshHours();
        }));
        court.addEventListener('change', refreshHours);
        startTime.addEventListener('change', updatePreview);
        endTime.addEventListener('change', updatePreview);
        widget.querySelectorAll('[data-weekday-preset]').forEach((button) => button.addEventListener('click', () => {
            const preset = button.dataset.weekdayPreset;
            const selected = preset === 'weekdays' ? [1, 2, 3, 4, 5] : preset === 'weekend' ? [0, 6] : [0, 1, 2, 3, 4, 5, 6];
            dayInputs.forEach((input) => { input.checked = !input.disabled && selected.includes(Number(input.value)); });
            renderWarnings([]); refreshHours();
        }));
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (widget.classList.contains('is-loading')) return;
            if (addButton.disabled || !startTime.value || !endTime.value || !selectedDays().length) return;
            const days = selectedDays();
            if (editingRule && days.length !== 1) { showMessage('Pick exactly one weekday while editing.', true); return; }
            renderWarnings([]);
            const common = { start_time: startTime.value, end_time: endTime.value, court_id: court.value || null, reason: form.elements.reason.value };
            setSaving(true);
            try {
                const result = editingRule
                    ? await api(`/admin/specific-closures/${editingRule.id}`, { method: 'PATCH', body: JSON.stringify({ ...common, weekday: days[0] }) })
                    : await api('/admin/specific-closures', { method: 'POST', body: JSON.stringify({ ...common, weekdays: days }) });
                renderList(result.closures || []);
                renderWarnings(result.warnings || []);
                showMessage(result.message);
                if (editingRule) form.reset(); else form.elements.reason.value = '';
                document.dispatchEvent(new CustomEvent('specific-closures:updated'));
            } catch (error) {
                showMessage(error.message, true);
                renderWarnings(error.data?.warnings || []);
            } finally {
                setSaving(false);
                addButton.disabled = !(startTime.value && endTime.value && selectedDays().length);
            }
        });

    form.addEventListener('reset', () => {
        leaveEditState();
        requestAnimationFrame(() => { showMessage(''); renderWarnings([]); refreshHours(); });
    });
        loadList();
        refreshHours();
    });
})();
