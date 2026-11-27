document.addEventListener('DOMContentLoaded', () => {

    /* ---------- Edit mode toggle ---------- */

    const form = document.querySelector('[data-profile-form]');
    const editBtn = document.querySelector('[data-profile-edit]');
    const saveBtn = document.querySelector('[data-profile-save]');
    const cancelBtn = document.querySelector('[data-profile-edit-cancel]');

    // Only fields explicitly marked by the role's profile view are unlocked.
    const editableFields = form
        ? form.querySelectorAll('[data-profile-editable="true"]')
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
    const deleteForm = document.getElementById('deleteAccountForm');
    const profilePage = document.querySelector('.user-profile-page');
    const contentScroller = document.querySelector('.user-profile-page .content') || profilePage?.closest('.content');

    const DELETE_PHRASE = 'DELETE MY ACCOUNT';

    function configureConfirmationDialog(modal, trigger, closeButtons, initialFocus, afterClose) {
        let returnFocus = null;
        let previousOverflow = '';
        const focusableSelector = 'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

        function open() {
            if (!modal || modal.classList.contains('open')) return;
            returnFocus = trigger;
            previousOverflow = contentScroller ? contentScroller.style.overflowY : '';
            if (contentScroller) contentScroller.style.overflowY = 'hidden';
            modal.classList.add('open');
            initialFocus?.focus();
        }

        function close() {
            if (!modal || !modal.classList.contains('open')) return;
            modal.classList.remove('open');
            modal.removeAttribute('aria-busy');
            if (contentScroller) contentScroller.style.overflowY = previousOverflow;
            afterClose?.();
            returnFocus?.focus();
        }

        closeButtons.forEach((button) => button?.addEventListener('click', close));
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) close();
        });
        modal?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }
            if (event.key !== 'Tab') return;

            const items = Array.from(modal.querySelectorAll(focusableSelector));
            if (!items.length) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        });

        trigger?.addEventListener('click', open);
        return { open, close };
    }

    const deleteDialog = configureConfirmationDialog(
        deleteModal,
        deleteBtn,
        [deleteModalClose, deleteModalCancel],
        deleteModalCancel,
        () => {
            if (deleteConfirmInput) deleteConfirmInput.value = '';
            if (deleteConfirmSubmit) deleteConfirmSubmit.disabled = true;
        }
    );

    deleteConfirmInput?.addEventListener('input', () => {
        if (!deleteConfirmSubmit) return;
        deleteConfirmSubmit.disabled = deleteConfirmInput.value !== DELETE_PHRASE;
    });

    let deleteSubmitting = false;
    deleteForm?.addEventListener('submit', (event) => {
        if (deleteSubmitting) {
            event.preventDefault();
            return;
        }
        deleteSubmitting = true;
        deleteModal?.setAttribute('aria-busy', 'true');
        if (deleteConfirmSubmit) deleteConfirmSubmit.disabled = true;
    });

    const logoutModal = document.getElementById('logoutModal');
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutForm = document.getElementById('logoutForm');
    const logoutModalClose = document.getElementById('logoutModalClose');
    const logoutModalCancel = document.getElementById('logoutModalCancel');
    const logoutConfirmSubmit = document.getElementById('logoutConfirmSubmit');
    configureConfirmationDialog(
        logoutModal,
        logoutBtn,
        [logoutModalClose, logoutModalCancel],
        logoutModalCancel
    );

    let logoutSubmitting = false;
    logoutConfirmSubmit?.addEventListener('click', () => {
        if (logoutSubmitting || !logoutForm) return;
        logoutSubmitting = true;
        logoutModal?.setAttribute('aria-busy', 'true');
        logoutConfirmSubmit.disabled = true;
        logoutForm.submit();
    });

    const passwordModal = document.getElementById('profilePasswordModal');
    const passwordTrigger = document.getElementById('profilePasswordButton');
    const passwordForm = document.getElementById('profilePasswordForm');
    const passwordSubmit = document.getElementById('profilePasswordSubmit');
    const passwordClose = document.getElementById('profilePasswordModalClose');
    const passwordCancel = document.getElementById('profilePasswordCancel');
    const passwordFocusSelector = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let passwordReturnFocus = null;
    let passwordPreviousOverflow = '';

    function passwordFocusableElements() {
        return Array.from(passwordModal?.querySelectorAll(passwordFocusSelector) || [])
            .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true' && element.getClientRects().length > 0);
    }

    function focusPasswordModal() {
        const invalidField = passwordModal?.querySelector('[aria-invalid="true"]');
        (invalidField || passwordModal?.querySelector('#profileCurrentPassword') || passwordFocusableElements()[0])?.focus();
    }

    function openPasswordModal() {
        if (!passwordModal || passwordTrigger?.disabled || passwordModal.classList.contains('open')) return;
        passwordReturnFocus = passwordTrigger;
        passwordPreviousOverflow = contentScroller ? contentScroller.style.overflowY : '';
        if (contentScroller) contentScroller.style.overflowY = 'hidden';
        passwordModal.hidden = false;
        passwordModal.classList.add('open');
        passwordModal.setAttribute('aria-hidden', 'false');
        focusPasswordModal();
    }

    function closePasswordModal() {
        if (!passwordModal || !passwordModal.classList.contains('open')) return;
        passwordModal.classList.remove('open');
        passwordModal.hidden = true;
        passwordModal.setAttribute('aria-hidden', 'true');
        passwordModal.removeAttribute('aria-busy');
        if (contentScroller) contentScroller.style.overflowY = passwordPreviousOverflow;
        passwordForm?.reset();
        passwordForm?.querySelectorAll('[data-password-visibility-toggle]').forEach((button) => {
            const input = document.getElementById(button.getAttribute('aria-controls'));
            if (input) input.type = 'password';
            button.textContent = 'Show';
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-label', `Show ${button.dataset.passwordLabel || 'password'}`);
        });
        passwordReturnFocus?.focus();
        passwordReturnFocus = null;
    }

    passwordTrigger?.addEventListener('click', openPasswordModal);
    passwordClose?.addEventListener('click', closePasswordModal);
    passwordCancel?.addEventListener('click', closePasswordModal);
    passwordModal?.addEventListener('click', (event) => {
        if (event.target === passwordModal) closePasswordModal();
    });
    passwordModal?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePasswordModal();
            return;
        }
        if (event.key !== 'Tab') return;

        const items = passwordFocusableElements();
        if (!items.length) {
            event.preventDefault();
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || !passwordModal.contains(document.activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !passwordModal.contains(document.activeElement))) {
            event.preventDefault();
            first.focus();
        }
    });

    passwordForm?.querySelectorAll('[data-password-visibility-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;

        button.dataset.passwordLabel = input.labels?.[0]?.textContent?.trim().toLowerCase() || 'password';
        button.addEventListener('click', () => {
            const showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            button.textContent = showing ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(showing));
            button.setAttribute('aria-label', `${showing ? 'Hide' : 'Show'} ${button.dataset.passwordLabel}`);
        });
    });

    let passwordSubmitting = false;
    passwordForm?.addEventListener('submit', (event) => {
        if (passwordSubmitting) {
            event.preventDefault();
            return;
        }
        passwordSubmitting = true;
        passwordModal?.setAttribute('aria-busy', 'true');
        if (passwordSubmit) passwordSubmit.disabled = true;
    });

    if (passwordModal?.classList.contains('open')) {
        passwordReturnFocus = passwordTrigger;
        passwordPreviousOverflow = contentScroller ? contentScroller.style.overflowY : '';
        if (contentScroller) contentScroller.style.overflowY = 'hidden';
        focusPasswordModal();
    }

    const profileThemeSwitch = document.querySelector('[data-profile-theme-switch]');
    const sharedThemeToggle = document.querySelector('[data-theme-toggle]');

    function syncProfileThemeSwitch() {
        if (!profileThemeSwitch) return;
        profileThemeSwitch.setAttribute('aria-checked', String(document.documentElement.dataset.theme !== 'light'));
    }

    profileThemeSwitch?.addEventListener('click', () => {
        sharedThemeToggle?.click();
        syncProfileThemeSwitch();
    });
    document.addEventListener('click', (event) => {
        if (event.target.closest?.('[data-theme-toggle]')) syncProfileThemeSwitch();
    });
    syncProfileThemeSwitch();

    /* ---------- Flash message toast (mirrors courts.js) ---------- */

    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');

    function showToast(message, type = 'success') {
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message"></span>
            <button type="button" class="toast-close btn-ghost-danger" aria-label="Dismiss">&times;</button>
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
            deleteDialog.open();
        }
    }
});
