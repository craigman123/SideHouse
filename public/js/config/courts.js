// courts.js — Add / Edit / Delete court modal logic

function openAddCourtModal() {
    document.getElementById('court-modal-title').textContent = 'Add Court';
    document.getElementById('court-form').reset();
    document.getElementById('court-form').action = window.COURTS_STORE_URL;
    document.getElementById('court-form-method').value = 'POST';
    document.getElementById('court-form-id').value = '';
    document.getElementById('court-form-submit').textContent = 'Save Court';
    clearFieldErrors();
    document.getElementById('court-modal').classList.add('open');
}

function openEditCourtModal(court) {
    document.getElementById('court-modal-title').textContent = 'Edit Court';

    const form = document.getElementById('court-form');
    form.action = window.COURTS_UPDATE_URL_TEMPLATE.replace(':id', court.id);
    document.getElementById('court-form-method').value = 'PUT';
    document.getElementById('court-form-id').value = court.id;
    document.getElementById('court-form-submit').textContent = 'Update Court';

    document.getElementById('name').value = court.name ?? '';
    document.getElementById('width').value = court.width ?? '';
    document.getElementById('length').value = court.length ?? '';
    document.getElementById('surface_type').value = court.surface_type ?? '';
    document.getElementById('hourly_rate').value = court.hourly_rate ?? '';
    document.getElementById('status').value = court.status ?? 'active';
    document.getElementById('notes').value = court.notes ?? '';

    clearFieldErrors();
    document.getElementById('court-modal').classList.add('open');
}

function closeCourtModal() {
    document.getElementById('court-modal').classList.remove('open');
}

function openDeleteCourtModal(courtId, courtName) {
    document.getElementById('delete-court-name').textContent = courtName;
    document.getElementById('delete-form').action = window.COURTS_DESTROY_URL_TEMPLATE.replace(':id', courtId);
    document.getElementById('delete-modal').classList.add('open');
}

function closeDeleteModal() {
    document.getElementById('delete-modal').classList.remove('open');
}

function clearFieldErrors() {
    document.querySelectorAll('.field-error').forEach((el) => {
        el.textContent = '';
    });
}

// Close modals when clicking the dark overlay itself
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('open');
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeCourtModal();
        closeDeleteModal();
    }
});

// On load: show toast for flash messages, reopen modal with errors if validation failed
document.addEventListener('DOMContentLoaded', function () {
    const flash = document.getElementById('flash-data');
    if (!flash) return;

    const success = flash.dataset.success;
    const error = flash.dataset.error;
    const errorsJson = flash.dataset.errors;
    const oldJson = flash.dataset.old;
    const oldCourtId = flash.dataset.oldCourtId;

    if (success && typeof showToast === 'function') {
        showToast(success, 'success');
    }
    if (error && typeof showToast === 'function') {
        showToast(error, 'error');
    }

    if (errorsJson) {
        const errors = JSON.parse(errorsJson);
        const old = oldJson ? JSON.parse(oldJson) : {};

        // Reopen the correct modal (edit if we have an old court_id, otherwise add)
        if (oldCourtId) {
            openEditCourtModal({ id: oldCourtId, ...old });
        } else {
            openAddCourtModal();
            Object.keys(old).forEach((key) => {
                const field = document.getElementById(key);
                if (field) field.value = old[key];
            });
        }

        Object.keys(errors).forEach((field) => {
            const el = document.querySelector(`.field-error[data-field="${field}"]`);
            if (el) el.textContent = errors[field][0];
        });
    }
});