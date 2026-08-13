document.addEventListener('DOMContentLoaded', () => {

    /* ---------- Edit mode toggle ---------- */

    const form = document.getElementById('profileForm');
    const editBtn = document.getElementById('editProfileBtn');
    const saveBtn = document.getElementById('saveProfileBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');

    // Only these fields are ever unlocked — role/dates/id stay readonly
    // and disabled no matter what, so they're never even submitted.
    const editableFields = form
        ? form.querySelectorAll('#name, #username, #email, #phone_number')
        : [];

    const originalValues = new Map();
    editableFields.forEach((field) => originalValues.set(field, field.value));

    function enterEditMode() {
        editableFields.forEach((field) => {
            field.readOnly = false;
            field.classList.add('editable');
        });

        editBtn.hidden = true;
        saveBtn.hidden = false;
        cancelBtn.hidden = false;

        editableFields[0]?.focus();
    }

    function exitEditMode() {
        editableFields.forEach((field) => {
            field.readOnly = true;
            field.classList.remove('editable');
            field.value = originalValues.get(field);
        });

        editBtn.hidden = false;
        saveBtn.hidden = true;
        cancelBtn.hidden = true;
    }

    editBtn?.addEventListener('click', enterEditMode);
    cancelBtn?.addEventListener('click', exitEditMode);

    /* ---------- Delete account modal ---------- */

    const deleteModal = document.getElementById('deleteAccountModal');
    const deleteBtn = document.getElementById('deleteAccountBtn');
    const deleteModalClose = document.getElementById('deleteModalClose');
    const deleteModalCancel = document.getElementById('deleteModalCancel');
    const deleteConfirmInput = document.getElementById('deleteConfirmInput');
    const deleteConfirmSubmit = document.getElementById('deleteConfirmSubmit');

    const DELETE_PHRASE = 'DELETE MY ACCOUNT';

    function openDeleteModal() {
        if (!deleteModal) return;
        deleteModal.classList.add('open');
        deleteConfirmInput?.focus();
    }

    function closeDeleteModal() {
        if (!deleteModal) return;
        deleteModal.classList.remove('open');
        if (deleteConfirmInput) deleteConfirmInput.value = '';
        if (deleteConfirmSubmit) deleteConfirmSubmit.disabled = true;
    }

    deleteBtn?.addEventListener('click', openDeleteModal);
    deleteModalClose?.addEventListener('click', closeDeleteModal);
    deleteModalCancel?.addEventListener('click', closeDeleteModal);
    deleteModal?.addEventListener('click', (e) => {
        if (e.target === deleteModal) closeDeleteModal();
    });

    deleteConfirmInput?.addEventListener('input', () => {
        if (!deleteConfirmSubmit) return;
        deleteConfirmSubmit.disabled = deleteConfirmInput.value !== DELETE_PHRASE;
    });

    /* ---------- Flash message toast (mirrors courts.js) ---------- */

    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');

    function showToast(message, type = 'success') {
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message"></span>
            <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>
        `;
        toast.querySelector('.toast-message').textContent = message;

        toastContainer.appendChild(toast);

        const remove = () => {
            toast.classList.add('toast-out');
            setTimeout(() => toast.remove(), 300);
        };

        toast.querySelector('.toast-close').addEventListener('click', remove);
        setTimeout(remove, 4000);
    }

    if (flash) {
        const success = flash.dataset.success;
        const error = flash.dataset.error;
        const deleteError = flash.dataset.deleteError;

        if (success) showToast(success, 'success');

        if (error) {
            showToast(error, 'error');
            // Validation failed (e.g. duplicate email) — reopen edit mode
            // so the person can see and fix what they typed, instead of
            // silently reverting to readonly with their edits lost.
            enterEditMode();
        }

        if (deleteError) {
            showToast(deleteError, 'error');
            openDeleteModal();
        }
    }
});