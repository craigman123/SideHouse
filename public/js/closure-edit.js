
(function () {
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('closureForm');
        if (!form) {
            return;
        }

        const storeUrl = form.dataset.storeUrl;
        const updateUrlTemplate = form.dataset.updateUrlTemplate;
        const methodInput = document.getElementById('closureFormMethod');
        const submitBtn = document.getElementById('closureSubmitBtn');
        const cancelBtn = document.getElementById('closureCancelEditBtn');
        const courtSelect = document.getElementById('closure_court');
        const reasonInput = document.getElementById('closure_reason');
        const datepickerRoot = document.querySelector('.sh-datepicker');

        function enterEditMode(btn) {
            const { id, date, courtId, reason } = btn.dataset;

            form.action = updateUrlTemplate.replace('__ID__', id);
            methodInput.value = 'PUT';
            submitBtn.textContent = 'Update Closure';
            cancelBtn.style.display = '';

            if (datepickerRoot && typeof datepickerRoot.shSetDate === 'function') {
                datepickerRoot.shSetDate(date || '');
            }
            courtSelect.value = courtId || '';
            reasonInput.value = reason || '';

            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function exitEditMode() {
            form.action = storeUrl;
            methodInput.value = '';
            submitBtn.textContent = 'Add Closure';
            cancelBtn.style.display = 'none';

            if (datepickerRoot && typeof datepickerRoot.shSetDate === 'function') {
                datepickerRoot.shSetDate('');
            }
            courtSelect.value = '';
            reasonInput.value = '';
        }

        document.querySelectorAll('.sh-closure-edit-btn').forEach((btn) => {
            btn.addEventListener('click', () => enterEditMode(btn));
        });

        cancelBtn.addEventListener('click', exitEditMode);
    });
})();