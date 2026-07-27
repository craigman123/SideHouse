document.addEventListener('DOMContentLoaded', () => {

  /* ---------- Topbar date ---------- */
  const topbarDate = document.getElementById('topbarDate');
  if (topbarDate) {
    topbarDate.textContent = new Date().toLocaleDateString(undefined, {
      weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    });
  }

  /* ---------- Live countdown to next booking (flip clock) ---------- */
  const countdownEl = document.getElementById('countdown');
  const nextIso = countdownEl ? countdownEl.dataset.next : '';
  const nextGameAt = nextIso ? new Date(nextIso) : null;

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  // Build a lookup of { tens: <flip-digit el>, ones: <flip-digit el> } per unit
  const flipUnits = {};
  if (countdownEl) {
    countdownEl.querySelectorAll('.flip-group').forEach((group) => {
      const unit = group.dataset.unit;
      flipUnits[unit] = {
        tens: group.querySelector('[data-digit="tens"]'),
        ones: group.querySelector('[data-digit="ones"]'),
      };
    });
  }

  function setDigit(digitEl, newValue) {
    if (!digitEl) return;
    const front = digitEl.querySelector('.flip-card-front');
    const back = digitEl.querySelector('.flip-card-back');
    const current = front.textContent;

    if (current === newValue) return; // nothing changed, don't animate

    back.textContent = newValue;
    digitEl.classList.add('is-flipping');

    const card = digitEl.querySelector('.flip-card');
    const onDone = () => {
      card.removeEventListener('transitionend', onDone);
      front.textContent = newValue;
      // Snap back to 0deg instantly (no transition) so the swap is invisible,
      // then restore the transition for the next flip.
      card.style.transition = 'none';
      digitEl.classList.remove('is-flipping');
      // eslint-disable-next-line no-unused-expressions
      card.offsetHeight; // force reflow
      card.style.transition = '';
    };
    card.addEventListener('transitionend', onDone);
  }

  function updateUnit(unit, value) {
    const digits = flipUnits[unit];
    if (!digits) return;
    const str = pad(value);
    setDigit(digits.tens, str[0]);
    setDigit(digits.ones, str[1]);
  }

  function updateCountdown() {
    const diff = nextGameAt ? nextGameAt - new Date() : -1;

    if (!nextGameAt || diff <= 0) {
      updateUnit('hours', 0);
      updateUnit('minutes', 0);
      updateUnit('seconds', 0);
      return;
    }

    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

    updateUnit('hours', hours);
    updateUnit('minutes', minutes);
    updateUnit('seconds', seconds);
  }

  if (countdownEl) {
    updateCountdown();
    setInterval(updateCountdown, 1000);
  }

  /* ---------- Milliseconds: plain counter (no flip animation) ---------- */
  const msTensEl = countdownEl ? countdownEl.querySelector('[data-unit="ms"] [data-digit="tens"]') : null;
  const msOnesEl = countdownEl ? countdownEl.querySelector('[data-unit="ms"] [data-digit="ones"]') : null;

  function updateMs() {
    const diff = nextGameAt ? nextGameAt - new Date() : -1;
    // Two digits, so this shows centiseconds (00–99) rather than full 0–999ms
    const centis = (!nextGameAt || diff <= 0) ? 0 : Math.floor((diff % 1000) / 10);
    const str = pad(centis);
    if (msTensEl) msTensEl.textContent = str[0];
    if (msOnesEl) msOnesEl.textContent = str[1];
  }

  if (msTensEl || msOnesEl) {
    updateMs();
    setInterval(updateMs, 30);
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