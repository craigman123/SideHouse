/**
 * Lightweight calendar popover for the admin Schedule "Closed Dates"
 * form. Supports single-select (default) and multi-select mode when
 * the root element has the .sh-datepicker-multi class.
 *
 * Multi-select: clicking a date toggles it on/off. Each selected date
 * gets its own <input type="hidden" name="dates[]"> injected into
 * #closure_dates_inputs so the controller receives an array.
 *
 * Court-aware blocking: a picker can be linked to a <select> (via
 * data-court-select="<id>") so it knows which court scope is currently
 * chosen. Any date that already has a closure for that exact scope
 * (same court, or store-wide "All Courts") gets disabled with a
 * strike-through — matching the server's own duplicate check — so it's
 * impossible to pick a date/court combo that would just get rejected
 * (or silently duplicate) on submit. When editing a closure, the
 * picker's own row is excluded from that check via
 * data-exclude-closure-id / shRefreshClosures(id).
 */
(function () {
    // Every mb-modal-overlay (manual-booking's calendar/time pickers, plus
    // the closure edit/delete modals) relies on position:fixed to cover the
    // whole screen. If any ancestor in the page shell (sidebar/content
    // wrapper, a sticky header, etc.) has a CSS transform/filter/
    // will-change, that ancestor becomes the fixed element's containing
    // block instead of the viewport — the overlay then only covers that
    // ancestor's box and gets shoved around by scroll position, instead of
    // dimming and centering over the whole page. Re-parenting every overlay
    // straight onto <body> sidesteps that regardless of what the rest of
    // the layout does upstream. This runs at the top of this (deferred)
    // script, which executes before the other deferred scripts' own
    // DOMContentLoaded handlers, so nothing else has cached a reference to
    // the old DOM position yet.
    document.querySelectorAll('.mb-modal-overlay').forEach(overlay => {
        if (overlay.parentElement !== document.body) {
            document.body.appendChild(overlay);
        }
    });

    const MONTH_LABELS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    function pad(n) { return String(n).padStart(2, '0'); }
    function toIso(y, m, d) { return `${y}-${pad(m + 1)}-${pad(d)}`; }
    function startOfToday() {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }

    // All closures on the page: [{ id, date, court_id }], court_id is
    // null for store-wide closures. Rendered server-side into a JSON
    // <script> tag so this file doesn't need its own endpoint.
    const CLOSURES_DATA = (function () {
        const el = document.getElementById('sh-closures-data');
        if (!el) return [];
        try {
            return JSON.parse(el.textContent || '[]');
        } catch (e) {
            return [];
        }
    })();

    // Dates that would be a wasted/duplicate closure for this court scope:
    // either an exact match (same court, or same "All Courts" scope), or —
    // when a specific court is selected — a date that's already closed
    // store-wide, since adding a per-court closure on top of that closes
    // nothing new. Returns a Map<iso, reason> so the UI can show why.
    // excludeId lets the closure currently being edited ignore its own row.
    function blockedDatesFor(courtValue, excludeId) {
        const courtId = courtValue === '' || courtValue == null ? null : Number(courtValue);
        const exclude = excludeId ? String(excludeId) : null;

        const map = new Map();
        CLOSURES_DATA.forEach(c => {
            if (String(c.id) === exclude) return;
            if (c.court_id === courtId) {
                map.set(c.date, 'Already closed for this exact selection.');
            } else if (courtId !== null && c.court_id === null && !map.has(c.date)) {
                map.set(c.date, 'Already closed for all courts that day.');
            }
        });
        return map;
    }

    function initDatepicker(root) {
        const isMulti = root.classList.contains('sh-datepicker-multi');

        const trigger     = root.querySelector('.sh-datepicker-trigger');
        const valueLabel  = root.querySelector('.sh-datepicker-value');
        const hiddenInput = root.querySelector('input[type="hidden"]'); // single mode only
        const datesWrap   = document.getElementById('closure_dates_inputs'); // multi mode
        const panel       = root.querySelector('.sh-datepicker-panel');
        const monthLabel  = root.querySelector('.sh-datepicker-month-label');
        const grid        = root.querySelector('.sh-datepicker-grid');
        const prevBtn     = root.querySelector('[data-dir="-1"]');
        const nextBtn     = root.querySelector('[data-dir="1"]');

        const existingDates = new Set(
            (root.dataset.existingDates || '')
                .split(',').map(s => s.trim()).filter(Boolean)
        );

        // Linked court <select>, if any (data-court-select="<id>").
        const courtSelectEl = root.dataset.courtSelect
            ? document.getElementById(root.dataset.courtSelect)
            : null;

        let blockedDates = courtSelectEl
            ? blockedDatesFor(courtSelectEl.value, root.dataset.excludeClosureId)
            : new Map();

        const today = startOfToday();
        let viewYear  = today.getFullYear();
        let viewMonth = today.getMonth();

        // ── State ───────────────────────────────────────────────────────────
        let selected    = null;           // single mode
        const selectedSet = new Set();    // multi mode

        // Restore single-mode value if the hidden input already has one
        if (!isMulti && hiddenInput && hiddenInput.value) {
            const parts = hiddenInput.value.split('-').map(Number);
            if (parts.length === 3 && !isNaN(parts[0])) {
                selected  = new Date(parts[0], parts[1] - 1, parts[2]);
                viewYear  = selected.getFullYear();
                viewMonth = selected.getMonth();
            }
        }

        function formatDisplay(date) {
            return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }

        // ── Multi-mode helpers ───────────────────────────────────────────────
        function syncMultiInputs() {
            if (!datesWrap) return;
            datesWrap.innerHTML = '';
            selectedSet.forEach(iso => {
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'dates[]';
                inp.value = iso;
                datesWrap.appendChild(inp);
            });
        }

        function updateMultiLabel() {
            if (!valueLabel) return;
            const count = selectedSet.size;
            if (count === 0) {
                valueLabel.textContent = 'Select one or more dates';
                valueLabel.classList.add('sh-datepicker-placeholder');
            } else {
                const sorted = [...selectedSet].sort();
                if (count === 1) {
                    const [y, m, d] = sorted[0].split('-').map(Number);
                    valueLabel.textContent = formatDisplay(new Date(y, m - 1, d));
                } else {
                    valueLabel.textContent = `${count} dates selected`;
                }
                valueLabel.classList.remove('sh-datepicker-placeholder');
            }
        }

        // Drop any current selection that a court change just made invalid.
        function pruneSelectionAgainstBlocked() {
            if (isMulti) {
                let changed = false;
                blockedDates.forEach((_reason, iso) => {
                    if (selectedSet.has(iso)) {
                        selectedSet.delete(iso);
                        changed = true;
                    }
                });
                if (changed) {
                    syncMultiInputs();
                    updateMultiLabel();
                }
            } else if (selected) {
                const iso = toIso(selected.getFullYear(), selected.getMonth(), selected.getDate());
                if (blockedDates.has(iso)) {
                    root.shSetDate('');
                }
            }
        }

        // ── Render ───────────────────────────────────────────────────────────
        function render() {
            monthLabel.textContent = `${MONTH_LABELS[viewMonth]} ${viewYear}`;
            grid.innerHTML = '';

            const firstOfMonth = new Date(viewYear, viewMonth, 1);
            const startWeekday = firstOfMonth.getDay();
            const daysInMonth  = new Date(viewYear, viewMonth + 1, 0).getDate();

            for (let i = 0; i < startWeekday; i++) {
                const blank = document.createElement('span');
                blank.className = 'sh-datepicker-day sh-datepicker-day-blank';
                grid.appendChild(blank);
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const cellDate = new Date(viewYear, viewMonth, day);
                const iso      = toIso(viewYear, viewMonth, day);
                const isBlocked = blockedDates.has(iso);

                const btn = document.createElement('button');
                btn.type        = 'button';
                btn.className   = 'sh-datepicker-day';
                btn.textContent = String(day);
                btn.dataset.iso = iso;

                if (cellDate < today) {
                    btn.disabled = true;
                    btn.classList.add('sh-datepicker-day-past');
                } else if (isBlocked) {
                    btn.disabled = true;
                    btn.classList.add('sh-datepicker-day-blocked');
                    btn.title = blockedDates.get(iso) || 'Already closed for this court selection.';
                }
                if (cellDate.getTime() === today.getTime()) {
                    btn.classList.add('sh-datepicker-day-today');
                }
                if (existingDates.has(iso)) {
                    btn.classList.add('sh-datepicker-day-has-closure');
                }

                if (isMulti) {
                    if (selectedSet.has(iso)) btn.classList.add('sh-datepicker-day-selected');
                    if (!isBlocked) {
                        btn.addEventListener('click', () => {
                            if (selectedSet.has(iso)) {
                                selectedSet.delete(iso);
                                btn.classList.remove('sh-datepicker-day-selected');
                            } else {
                                selectedSet.add(iso);
                                btn.classList.add('sh-datepicker-day-selected');
                            }
                            syncMultiInputs();
                            updateMultiLabel();
                            // Don't close — let admin pick more dates
                        });
                    }
                } else {
                    if (selected && cellDate.getTime() === selected.getTime()) {
                        btn.classList.add('sh-datepicker-day-selected');
                    }
                    if (!isBlocked) {
                        btn.addEventListener('click', () => {
                            selected = cellDate;
                            if (hiddenInput) hiddenInput.value = iso;
                            if (valueLabel) {
                                valueLabel.textContent = formatDisplay(cellDate);
                                valueLabel.classList.remove('sh-datepicker-placeholder');
                            }
                            closePanel();
                        });
                    }
                }

                grid.appendChild(btn);
            }
        }

        // ── Panel open/close ─────────────────────────────────────────────────
        function openPanel() {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            render();
            document.addEventListener('click', onOutsideClick);
            document.addEventListener('keydown', onKeydown);
        }

        function closePanel() {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', onOutsideClick);
            document.removeEventListener('keydown', onKeydown);
        }

        function onOutsideClick(e) { if (!root.contains(e.target)) closePanel(); }
        function onKeydown(e) {
            if (e.key === 'Escape') { closePanel(); trigger.focus(); }
        }

        trigger.addEventListener('click', () => panel.hidden ? openPanel() : closePanel());
        prevBtn.addEventListener('click', () => {
            viewMonth -= 1;
            if (viewMonth < 0) { viewMonth = 11; viewYear -= 1; }
            render();
        });
        nextBtn.addEventListener('click', () => {
            viewMonth += 1;
            if (viewMonth > 11) { viewMonth = 0; viewYear += 1; }
            render();
        });

        if (courtSelectEl) {
            courtSelectEl.addEventListener('change', () => {
                blockedDates = blockedDatesFor(courtSelectEl.value, root.dataset.excludeClosureId);
                pruneSelectionAgainstBlocked();
                render();
            });
        }

        // External API — lets closure-edit.js push a date in (single mode only)
        root.shSetDate = function (iso) {
            if (!iso) {
                selected = null;
                if (hiddenInput) hiddenInput.value = '';
                if (valueLabel) {
                    valueLabel.textContent = 'Select a date';
                    valueLabel.classList.add('sh-datepicker-placeholder');
                }
                const t = startOfToday();
                viewYear = t.getFullYear(); viewMonth = t.getMonth();
                return;
            }
            const parts = iso.split('-').map(Number);
            selected  = new Date(parts[0], parts[1] - 1, parts[2]);
            viewYear  = selected.getFullYear();
            viewMonth = selected.getMonth();
            if (hiddenInput) hiddenInput.value = iso;
            if (valueLabel) {
                valueLabel.textContent = formatDisplay(selected);
                valueLabel.classList.remove('sh-datepicker-placeholder');
            }
        };

        // Multi-mode reset (called by form reset after submission)
        root.shClearDates = function () {
            selectedSet.clear();
            syncMultiInputs();
            updateMultiLabel();
        };

        // External API — recompute which dates are blocked (e.g. the Edit
        // modal calls this with the closure's own id right after opening,
        // so that closure's own date doesn't count as "already taken").
        root.shRefreshClosures = function (excludeId) {
            root.dataset.excludeClosureId = excludeId || '';
            blockedDates = courtSelectEl
                ? blockedDatesFor(courtSelectEl.value, excludeId)
                : new Map();
            pruneSelectionAgainstBlocked();
            render();
        };
    }

    // ── Toast auto-dismiss ─────────────────────────────────────────────────
    function initToasts() {
        document.querySelectorAll('.schedule-toast').forEach(toast => {
            // Auto-dismiss after 5 s
            const timer = setTimeout(() => dismissToast(toast), 5000);

            const closeBtn = toast.querySelector('.schedule-toast-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    clearTimeout(timer);
                    dismissToast(toast);
                });
            }
        });
    }

    function dismissToast(toast) {
        toast.classList.add('schedule-toast-hide');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }

    // NOTE: there used to be a "clear the picker on submit" step here that
    // called shClearDates() inside the form's submit handler. Submit
    // handlers run synchronously *before* the browser serializes the form,
    // so that wiped the dates[] hidden inputs out from under the request —
    // the server always received an empty `dates` array, which is why the
    // "Dates field is required" error showed up even after picking dates.
    // The form does a full page reload on submit anyway, so there's nothing
    // to reset client-side — the fresh page load handles that for free.

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.sh-datepicker').forEach(initDatepicker);
        initToasts();
    });
})();