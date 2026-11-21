(() => {
    const form = document.getElementById('staffLogoutForm');
    const trigger = document.getElementById('staffLogoutButton');
    const modal = document.getElementById('staffLogoutModal');
    const closeButton = document.getElementById('staffLogoutModalClose');
    const cancelButton = document.getElementById('staffLogoutModalCancel');
    const confirmButton = document.getElementById('staffLogoutConfirm');

    if (!form || !trigger || !modal || !closeButton || !cancelButton || !confirmButton) return;

    let returnFocus = null;

    function openModal() {
        returnFocus = trigger;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('open');
        cancelButton.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        returnFocus?.focus();
        returnFocus = null;
    }

    trigger.addEventListener('click', openModal);
    closeButton.addEventListener('click', closeModal);
    cancelButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
        }
    });
    confirmButton.addEventListener('click', () => {
        confirmButton.disabled = true;
        form.submit();
    });
})();
