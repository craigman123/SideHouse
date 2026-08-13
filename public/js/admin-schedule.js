/**
 * Lightweight calendar popover for the admin Schedule "Closed Dates"
 * form. No dependencies — swaps in for the native <input type="date">
 * so the styling matches the rest of the app. Writes the picked date
 * (YYYY-MM-DD) into the paired hidden input, which is what actually
 * gets submitted with the form.
 */
(function () {
    const MONTH_LABELS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function toIso(y, m, d) {
        return `${y}-${pad(m + 1)}-${pad(d)}`;
    }

    function startOfToday() {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }

    function initDatepicker(root) {
        const trigger = root.querySelector('.sh-datepicker-trigger');
        const valueLabel = root.querySelector('.sh-datepicker-value');
        const hiddenInput = root.querySelector('input[type="hidden"]');
        const panel = root.querySelector('.sh-datepicker-panel');
        const monthLabel = root.querySelector('.sh-datepicker-month-label');
        const grid = root.querySelector('.sh-datepicker-grid');
        const prevBtn = root.querySelector('[data-dir="-1"]');
        const nextBtn = root.querySelector('[data-dir="1"]');

        const existingDates = new Set(
            (root.dataset.existingDates || '')
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean)
        );

        const today = startOfToday();

        let viewYear = today.getFullYear();
        let viewMonth = today.getMonth();
        let selected = null;

        if (hiddenInput.value) {
            const parts = hiddenInput.value.split('-').map(Number);
            if (parts.length === 3 && !isNaN(parts[0])) {
                selected = new Date(parts[0], parts[1] - 1, parts[2]);
                viewYear = selected.getFullYear();
                viewMonth = selected.getMonth();
            }
        }

        function formatDisplay(date) {
            return date.toLocaleDateString('en-US', {
                month: 'short', day: '2-digit', year: 'numeric',
            });
        }

        function render() {
            monthLabel.textContent = `${MONTH_LABELS[viewMonth]} ${viewYear}`;
            grid.innerHTML = '';

            const firstOfMonth = new Date(viewYear, viewMonth, 1);
            const startWeekday = firstOfMonth.getDay();
            const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

            for (let i = 0; i < startWeekday; i++) {
                const blank = document.createElement('span');
                blank.className = 'sh-datepicker-day sh-datepicker-day-blank';
                grid.appendChild(blank);
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const cellDate = new Date(viewYear, viewMonth, day);
                const iso = toIso(viewYear, viewMonth, day);

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sh-datepicker-day';
                btn.textContent = String(day);
                btn.dataset.iso = iso;

                if (cellDate < today) {
                    btn.disabled = true;
                    btn.classList.add('sh-datepicker-day-past');
                }

                if (cellDate.getTime() === today.getTime()) {
                    btn.classList.add('sh-datepicker-day-today');
                }

                if (selected && cellDate.getTime() === selected.getTime()) {
                    btn.classList.add('sh-datepicker-day-selected');
                }

                if (existingDates.has(iso)) {
                    btn.classList.add('sh-datepicker-day-has-closure');
                }

                btn.addEventListener('click', () => {
                    selected = cellDate;
                    hiddenInput.value = iso;
                    valueLabel.textContent = formatDisplay(cellDate);
                    valueLabel.classList.remove('sh-datepicker-placeholder');
                    closePanel();
                });

                grid.appendChild(btn);
            }
        }

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

        function onOutsideClick(e) {
            if (!root.contains(e.target)) {
                closePanel();
            }
        }

        function onKeydown(e) {
            if (e.key === 'Escape') {
                closePanel();
                trigger.focus();
            }
        }

        trigger.addEventListener('click', () => {
            panel.hidden ? openPanel() : closePanel();
        });

        prevBtn.addEventListener('click', () => {
            viewMonth -= 1;
            if (viewMonth < 0) {
                viewMonth = 11;
                viewYear -= 1;
            }
            render();
        });

        nextBtn.addEventListener('click', () => {
            viewMonth += 1;
            if (viewMonth > 11) {
                viewMonth = 0;
                viewYear += 1;
            }
            render();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.sh-datepicker').forEach(initDatepicker);
    });
})();
