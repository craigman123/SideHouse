document.addEventListener('DOMContentLoaded', () => {

  /* ---------- Topbar date ---------- */
  const topbarDate = document.getElementById('topbarDate');
  if (topbarDate) {
    topbarDate.textContent = new Date().toLocaleDateString(undefined, {
      weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    });
  }

  /* ---------- Live countdown to next booking ---------- */
  // Swap this for the real booking datetime from your backend,
  // e.g. new Date("{{ $nextBooking->starts_at }}")
  const nextGameAt = new Date();
  nextGameAt.setDate(nextGameAt.getDate() + 1);
  nextGameAt.setHours(18, 0, 0, 0);

  const cdHours = document.getElementById('cdHours');
  const cdMinutes = document.getElementById('cdMinutes');
  const cdSeconds = document.getElementById('cdSeconds');

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function updateCountdown() {
    const diff = nextGameAt - new Date();

    if (diff <= 0) {
      cdHours.textContent = '00';
      cdMinutes.textContent = '00';
      cdSeconds.textContent = '00';
      return;
    }

    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

    cdHours.textContent = pad(hours);
    cdMinutes.textContent = pad(minutes);
    cdSeconds.textContent = pad(seconds);
  }

  if (cdHours && cdMinutes && cdSeconds) {
    updateCountdown();
    setInterval(updateCountdown, 1000);
  }

  /* ---------- Profile dropdown ---------- */
  const profileTrigger = document.getElementById('profileTrigger');
  const profileDropdown = document.getElementById('profileDropdown');

  if (profileTrigger && profileDropdown) {
    profileTrigger.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = profileDropdown.classList.toggle('open');
      profileTrigger.setAttribute('aria-expanded', isOpen);
    });

    document.addEventListener('click', () => {
      profileDropdown.classList.remove('open');
      profileTrigger.setAttribute('aria-expanded', 'false');
    });

    const cancelBtn = profileDropdown.querySelector('.dropdown-cancel');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        profileDropdown.classList.remove('open');
      });
    }
  }

  /* ---------- Cancel booking modal ---------- */
  const modal = document.getElementById('cancelModal');
  const modalText = document.getElementById('modalText');
  const modalClose = document.getElementById('modalClose');
  const modalKeep = document.getElementById('modalKeep');
  const modalConfirm = document.getElementById('modalConfirm');

  let rowPendingCancel = null;

  function openModal(row) {
    rowPendingCancel = row;
    const label = row.dataset.booking || 'this booking';
    modalText.textContent = `Cancel ${label}? This will free up the slot for other players. This can't be undone.`;
    modal.classList.add('open');
  }

  function closeModal() {
    modal.classList.remove('open');
    rowPendingCancel = null;
  }

  document.querySelectorAll('[data-cancel]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const row = btn.closest('tr');
      openModal(row);
    });
  });

  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (modalKeep) modalKeep.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });
  }

  if (modalConfirm) {
    modalConfirm.addEventListener('click', () => {
      if (rowPendingCancel) {
        const row = rowPendingCancel;
        row.classList.add('row-removing');
        setTimeout(() => row.remove(), 250);
        showToast('Booking cancelled.', 'success');
      }
      closeModal();
    });
  }

  /* ---------- Toasts ---------- */
  const toastContainer = document.getElementById('toastContainer');

  function showToast(message, type = 'success') {
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span class="toast-message">${message}</span>
      <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>`;

    toastContainer.appendChild(toast);

    const dismiss = () => {
      toast.classList.add('toast-out');
      setTimeout(() => toast.remove(), 300);
    };

    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    setTimeout(dismiss, 4000);
  }
});
