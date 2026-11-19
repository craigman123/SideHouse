/**
 * Closed Dates: Edit and Remove modals.
 *
 * Edit opens #closureEditModal, pre-fills its own single-select date
 * picker (#closureEditDatepicker) plus court/reason from the row's
 * data-* attributes, and points the form at the update route for that
 * closure's id.
 *
 * Remove opens #closureDeleteModal for confirmation instead of the
 * browser's native confirm(). Each row already renders its own hidden
 * <form id="closureDeleteForm{id}"> with the DELETE request wired up —
 * this just submits the right one once the admin confirms.
 */
(function () {
    document.addEventListener('DOMContentLoaded', () => {
        initEditModal();
        initDeleteModal();
    });

    function initEditModal() {
        const modal = document.getElementById('closureEditModal');
        const form = document.getElementById('closureEditForm');
        if (!modal || !form) return;

        const updateUrlTemplate = form.dataset.updateUrlTemplate;
        const courtSelect = document.getElementById('closure_edit_court');
        const reasonInput = document.getElementById('closure_edit_reason');
        const datepickerRoot = document.getElementById('closureEditDatepicker');
        const closeBtn = document.getElementById('closureEditClose');
        const cancelBtn = document.getElementById('closureEditCancel');

        function openModal(btn) {
            const { id, date, courtId, reason } = btn.dataset;

            form.action = updateUrlTemplate.replace('__ID__', id);

            courtSelect.value = courtId || '';
            reasonInput.value = reason || '';

            // Recompute which dates are blocked for the now-selected court,
            // excluding this closure's own row — otherwise its own date
            // would show up as "already closed" against itself.
            if (datepickerRoot && typeof datepickerRoot.shRefreshClosures === 'function') {
                datepickerRoot.shRefreshClosures(id);
            }
            if (datepickerRoot && typeof datepickerRoot.shSetDate === 'function') {
                datepickerRoot.shSetDate(date || '');
            }

            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
        }

        document.querySelectorAll('.sh-closure-edit-btn').forEach((btn) => {
            btn.addEventListener('click', () => openModal(btn));
        });

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });
    }

    function initDeleteModal() {
        const modal = document.getElementById('closureDeleteModal');
        if (!modal) return;

        const textEl = document.getElementById('closureDeleteText');
        const closeBtn = document.getElementById('closureDeleteClose');
        const cancelBtn = document.getElementById('closureDeleteCancel');
        const confirmBtn = document.getElementById('closureDeleteConfirm');

        let pendingFormId = null;

        function openModal(btn) {
            pendingFormId = btn.dataset.formId;
            textEl.textContent = btn.dataset.label
                ? `Remove the closure for ${btn.dataset.label}?`
                : 'This will reopen the court for this date.';
            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
            pendingFormId = null;
        }

        document.querySelectorAll('.sh-closure-delete-btn').forEach((btn) => {
            btn.addEventListener('click', () => openModal(btn));
        });

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });

        confirmBtn.addEventListener('click', () => {
            if (!pendingFormId) return;
            const form = document.getElementById(pendingFormId);
            if (form) form.submit();
        });
    }
})();