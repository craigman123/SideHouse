(() => {
    const profileForm = document.getElementById('staffProfileForm');

    if (profileForm) {
        const fields = [
            document.getElementById('staffName'),
            document.getElementById('staffPhone'),
        ].filter(Boolean);
        const editButton = document.getElementById('staffProfileEditButton');
        const cancelButton = document.getElementById('staffProfileCancelButton');
        const saveButton = document.getElementById('staffProfileSaveButton');
        const originalValues = new Map(fields.map((field) => [field, field.value]));

        function setEditing(editing, restore = false) {
            fields.forEach((field) => {
                if (restore) field.value = originalValues.get(field);
                field.readOnly = !editing;
                field.classList.toggle('editable', editing);
            });
            if (editButton) editButton.hidden = editing;
            if (cancelButton) cancelButton.hidden = !editing;
            if (saveButton) saveButton.hidden = !editing;
        }

        editButton?.addEventListener('click', () => setEditing(true));
        cancelButton?.addEventListener('click', () => setEditing(false, true));

        if (profileForm.dataset.editOnError === 'true') setEditing(true);
    }

    const passwordTrigger = document.getElementById('staffChangePasswordButton');
    const passwordModal = document.getElementById('staffPasswordModal');
    const passwordCloseButton = document.getElementById('staffPasswordModalClose');
    const passwordCancelButton = document.getElementById('staffPasswordModalCancel');

    if (passwordTrigger && passwordModal && passwordCloseButton && passwordCancelButton) {
        let passwordReturnFocus = null;
        const focusableSelector = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

        function getPasswordModalFocusableElements() {
            return Array.from(passwordModal.querySelectorAll(focusableSelector))
                .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true' && element.getClientRects().length > 0);
        }

        function focusPasswordModal() {
            const invalidField = passwordModal.querySelector('[aria-invalid="true"]');
            const focusTarget = invalidField || passwordModal.querySelector('#staffCurrentPassword') || getPasswordModalFocusableElements()[0];
            focusTarget?.focus();
        }

        function openPasswordModal() {
            passwordReturnFocus = passwordTrigger;
            passwordModal.hidden = false;
            passwordModal.classList.add('open');
            passwordModal.setAttribute('aria-hidden', 'false');
            focusPasswordModal();
        }

        function closePasswordModal() {
            passwordModal.classList.remove('open');
            passwordModal.hidden = true;
            passwordModal.setAttribute('aria-hidden', 'true');
            passwordReturnFocus?.focus();
            passwordReturnFocus = null;
        }

        passwordTrigger.addEventListener('click', openPasswordModal);
        passwordCloseButton.addEventListener('click', closePasswordModal);
        passwordCancelButton.addEventListener('click', closePasswordModal);
        passwordModal.addEventListener('click', (event) => {
            if (event.target === passwordModal) closePasswordModal();
        });
        passwordModal.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closePasswordModal();
                return;
            }

            if (event.key !== 'Tab') return;

            const focusableElements = getPasswordModalFocusableElements();
            if (focusableElements.length === 0) {
                event.preventDefault();
                return;
            }

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            if (event.shiftKey && document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
            } else if (!event.shiftKey && document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            } else if (!passwordModal.contains(document.activeElement)) {
                event.preventDefault();
                firstElement.focus();
            }
        });

        if (!passwordModal.hidden) {
            passwordReturnFocus = passwordTrigger;
            focusPasswordModal();
        }
    }

    const logoutForm = document.getElementById('staffLogoutForm');
    const logoutTrigger = document.getElementById('staffLogoutButton');
    const logoutModal = document.getElementById('staffLogoutModal');
    const logoutCloseButton = document.getElementById('staffLogoutModalClose');
    const logoutCancelButton = document.getElementById('staffLogoutModalCancel');
    const logoutConfirmButton = document.getElementById('staffLogoutConfirm');

    if (!logoutForm || !logoutTrigger || !logoutModal || !logoutCloseButton || !logoutCancelButton || !logoutConfirmButton) return;

    let logoutReturnFocus = null;

    function openLogoutModal() {
        logoutReturnFocus = logoutTrigger;
        logoutModal.hidden = false;
        logoutModal.setAttribute('aria-hidden', 'false');
        logoutModal.classList.add('open');
        logoutCancelButton.focus();
    }

    function closeLogoutModal() {
        logoutModal.classList.remove('open');
        logoutModal.hidden = true;
        logoutModal.setAttribute('aria-hidden', 'true');
        logoutReturnFocus?.focus();
        logoutReturnFocus = null;
    }

    logoutTrigger.addEventListener('click', openLogoutModal);
    logoutCloseButton.addEventListener('click', closeLogoutModal);
    logoutCancelButton.addEventListener('click', closeLogoutModal);
    logoutModal.addEventListener('click', (event) => {
        if (event.target === logoutModal) closeLogoutModal();
    });
    logoutModal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeLogoutModal();
        }
    });
    logoutConfirmButton.addEventListener('click', () => {
        logoutConfirmButton.disabled = true;
        logoutForm.submit();
    });
})();
