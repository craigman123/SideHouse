document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('specific-closure-form');
    if (!form) return;

    const wrapper = document.getElementById('tc-datepicker');
    const trigger = document.getElementById('tc-datepicker-input');
    const label = document.getElementById('tc-datepicker-label');
    const panel = document.getElementById('tc-datepicker-panel');
    const monthLabel = document.getElementById('tc-dp-month-label');
    const grid = document.getElementById('tc-dp-grid');
    const prevBtn = document.getElementById('tc-dp-prev');
    const nextBtn = document.getElementById('tc-dp-next');
    const clearBtn = document.getElementById('tc-dp-clear');
    const doneBtn = document.getElementById('tc-dp-done');
    const hiddenContainer = document.getElementById('tc-hidden-inputs');
    const submitBtn = document.getElementById('tc-submit-btn');

    // Dates that already have a closure entry — shown as disabled so they
    // can't be re-picked and duplicated.
    const existingDates = new Set(
        (hiddenContainer.dataset.existingDates || '')
            .split(',')
            .filter(Boolean)
    );

    const selectedDates = new Set();
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let viewYear = today.getFullYear();
    let viewMonth = today.getMonth(); // 0-indexed

    const monthFormatter = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });

    function toIso(year, month, day) {
        const mm = String(month + 1).padStart(2, '0');
        const dd = String(day).padStart(2, '0');
        return `${year}-${mm}-${dd}`;
    }

    function updateLabel() {
        if (selectedDates.size === 0) {
            label.textContent = 'Select dates';
            label.classList.remove('has-value');
        } else {
            label.textContent = `${selectedDates.size} date${selectedDates.size === 1 ? '' : 's'} selected`;
            label.classList.add('has-value');
        }
        submitBtn.disabled = selectedDates.size === 0;
    }

    function syncHiddenInputs() {
        hiddenContainer.querySelectorAll('input[name="dates[]"]').forEach((el) => el.remove());
        selectedDates.forEach((isoDate) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'dates[]';
            hidden.value = isoDate;
            hiddenContainer.appendChild(hidden);
        });
    }

    function renderGrid() {
        monthLabel.textContent = monthFormatter.format(new Date(viewYear, viewMonth, 1));
        grid.innerHTML = '';

        const firstOfMonth = new Date(viewYear, viewMonth, 1);
        const startOffset = firstOfMonth.getDay(); // 0 = Sunday
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        const daysInPrevMonth = new Date(viewYear, viewMonth, 0).getDate();

        const totalCells = Math.ceil((startOffset + daysInMonth) / 7) * 7;

        for (let cell = 0; cell < totalCells; cell++) {
            const dayNum = cell - startOffset + 1;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tc-dp-day';

            let cellYear = viewYear;
            let cellMonth = viewMonth;
            let cellDay = dayNum;
            let outOfMonth = false;

            if (dayNum < 1) {
                cellMonth = viewMonth - 1;
                cellDay = daysInPrevMonth + dayNum;
                outOfMonth = true;
                if (cellMonth < 0) { cellMonth = 11; cellYear -= 1; }
            } else if (dayNum > daysInMonth) {
                cellMonth = viewMonth + 1;
                cellDay = dayNum - daysInMonth;
                outOfMonth = true;
                if (cellMonth > 11) { cellMonth = 0; cellYear += 1; }
            }

            const iso = toIso(cellYear, cellMonth, cellDay);
            const cellDate = new Date(cellYear, cellMonth, cellDay);
            cellDate.setHours(0, 0, 0, 0);

            btn.textContent = String(cellDay);
            btn.dataset.date = iso;

            if (outOfMonth) btn.classList.add('tc-dp-day-muted');
            if (cellDate.getTime() === today.getTime()) btn.classList.add('tc-dp-day-today');

            const isPast = cellDate.getTime() < today.getTime();
            const isExisting = existingDates.has(iso);

            if (isPast) {
                btn.classList.add('tc-dp-day-disabled');
                btn.disabled = true;
            } else if (isExisting) {
                btn.classList.add('tc-dp-day-existing');
                btn.title = 'Already has a closure';
                btn.disabled = true;
            } else if (selectedDates.has(iso)) {
                btn.classList.add('tc-dp-day-selected');
            }

            grid.appendChild(btn);
        }
    }

    function openPanel() {
        panel.hidden = false;
        wrapper.classList.add('open');
    }

    function closePanel() {
        panel.hidden = true;
        wrapper.classList.remove('open');
    }

    trigger.addEventListener('click', () => {
        if (panel.hidden) {
            renderGrid();
            openPanel();
        } else {
            closePanel();
        }
    });

    grid.addEventListener('click', (event) => {
        const btn = event.target.closest('.tc-dp-day');
        if (!btn || btn.disabled) return;

        const iso = btn.dataset.date;

        if (selectedDates.has(iso)) {
            selectedDates.delete(iso);
            btn.classList.remove('tc-dp-day-selected');
        } else {
            selectedDates.add(iso);
            btn.classList.add('tc-dp-day-selected');
        }

        updateLabel();
        syncHiddenInputs();
    });

    prevBtn.addEventListener('click', () => {
        viewMonth -= 1;
        if (viewMonth < 0) { viewMonth = 11; viewYear -= 1; }
        renderGrid();
    });

    nextBtn.addEventListener('click', () => {
        viewMonth += 1;
        if (viewMonth > 11) { viewMonth = 0; viewYear += 1; }
        renderGrid();
    });

    clearBtn.addEventListener('click', () => {
        selectedDates.clear();
        updateLabel();
        syncHiddenInputs();
        renderGrid();
    });

    doneBtn.addEventListener('click', closePanel);

    document.addEventListener('click', (event) => {
        if (!wrapper.contains(event.target)) closePanel();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePanel();
    });

    form.addEventListener('submit', (event) => {
        if (selectedDates.size === 0) event.preventDefault();
    });

    updateLabel();
});

// --- Specific Date/Time Closure: edit/remove modals ---------------------
document.addEventListener('DOMContentLoaded', () => {
    const editModal = document.getElementById('editSpecificClosureModal');
    const removeModal = document.getElementById('removeSpecificClosureModal');
    if (!editModal && !removeModal) return;

    const openModal = (modal) => {
        modal.classList.add('active');
        document.body.classList.add('mb-modal-open');
    };

    const closeModal = (modal) => {
        modal.classList.remove('active');
        document.body.classList.remove('mb-modal-open');
    };

    // ---- Edit ----
    const editForm = document.getElementById('specificClosureEditForm');
    const editDateInput = document.getElementById('specific_closure_edit_date');
    const editTimeInput = document.getElementById('specific_closure_edit_time');
    const urlTemplate = editForm?.dataset.updateUrlTemplate;

    document.querySelectorAll('.sh-specific-closure-edit-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!editModal || !editForm) return;

            editForm.action = urlTemplate.replace('__ID__', btn.dataset.id);
            editDateInput.value = btn.dataset.date;
            editTimeInput.value = btn.dataset.time;

            openModal(editModal);
        });
    });

    document.getElementById('specificClosureEditClose')?.addEventListener('click', () => closeModal(editModal));
    document.getElementById('specificClosureEditCancel')?.addEventListener('click', () => closeModal(editModal));

    // editForm submits normally (real POST/PUT) once the user hits "Save Changes" —
    // nothing to intercept here, this is the actual confirmation step.

    // ---- Remove ----
    let pendingDeleteForm = null;

    document.querySelectorAll('.sh-specific-closure-delete-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!removeModal) return;

            pendingDeleteForm = document.getElementById(btn.dataset.formId);

            const text = document.getElementById('specificClosureDeleteText');
            if (text) {
                text.textContent = `Remove the closure for ${btn.dataset.label}? This will reopen bookings for that date/time.`;
            }

            openModal(removeModal);
        });
    });

    document.getElementById('specificClosureDeleteClose')?.addEventListener('click', () => {
        pendingDeleteForm = null;
        closeModal(removeModal);
    });
    document.getElementById('specificClosureDeleteCancel')?.addEventListener('click', () => {
        pendingDeleteForm = null;
        closeModal(removeModal);
    });

    document.getElementById('specificClosureDeleteConfirm')?.addEventListener('click', () => {
        pendingDeleteForm?.requestSubmit();
    });

    // Backdrop click + Escape close either modal without acting.
    [editModal, removeModal].forEach((modal) => {
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) closeModal(modal);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        [editModal, removeModal].forEach((modal) => {
            if (modal?.classList.contains('active')) closeModal(modal);
        });
    });
});