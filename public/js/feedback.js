document.addEventListener('DOMContentLoaded', function () {
    const wrapper = document.getElementById('starRating');
    const input = document.getElementById('ratingInput');
    const form = document.getElementById('feedbackForm');

    if (!wrapper || !input) return;

    const stars = Array.from(wrapper.querySelectorAll('.star-btn'));

    function paint(value) {
        stars.forEach((btn) => {
            const starValue = Number(btn.dataset.star);
            const filled = starValue <= value;
            btn.classList.toggle('filled', filled);
            btn.setAttribute('aria-checked', String(starValue === value));
        });
    }

    function setRating(value) {
        input.value = value;
        wrapper.dataset.value = value;
        paint(value);
        clearError();
    }

    function clearError() {
        const errorEl = document.querySelector('.field-error[data-field="rating"]');
        if (errorEl) errorEl.textContent = '';
    }

    stars.forEach((btn) => {
        btn.addEventListener('click', () => setRating(Number(btn.dataset.star)));
        btn.addEventListener('mouseenter', () => paint(Number(btn.dataset.star)));
        btn.addEventListener('focus', () => paint(Number(btn.dataset.star)));
    });

    wrapper.addEventListener('mouseleave', () => paint(Number(input.value) || 0));

    // Keyboard support — left/right (or up/down) to move the selection,
    // since these are plain buttons in a role="radiogroup", not native
    // radio inputs, so arrow-key handling isn't free.
    wrapper.addEventListener('keydown', (e) => {
        const current = Number(input.value) || 0;
        if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
            e.preventDefault();
            setRating(Math.min(5, current + 1 || 1));
            stars[Math.min(5, current + 1 || 1) - 1].focus();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
            e.preventDefault();
            setRating(Math.max(1, current - 1));
            stars[Math.max(1, current - 1) - 1].focus();
        }
    });

    // Restore a rating carried over from a failed submission (old('rating'))
    const initial = Number(wrapper.dataset.value) || 0;
    if (initial) setRating(initial);

    if (form) {
        form.addEventListener('submit', (e) => {
            if (!input.value) {
                e.preventDefault();
                const errorEl = document.querySelector('.field-error[data-field="rating"]');
                if (errorEl) errorEl.textContent = 'Please select a rating before submitting.';
                wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }
});

// Edit/Cancel toggle for each entry in "Your Previous Feedback". Uses
// event delegation on document rather than binding a listener per entry,
// since the list can have any number of items and this way needs no
// per-entry ids to keep track of — .closest('.feedback-entry') finds the
// right card to toggle no matter which entry's button was clicked.
document.addEventListener('click', function (e) {
    const editBtn = e.target.closest('.btn-edit-toggle');
    if (editBtn) {
        const entry = editBtn.closest('.feedback-entry');
        if (!entry) return;
        entry.querySelector('.feedback-view').hidden = true;
        entry.querySelector('.feedback-edit').hidden = false;
        return;
    }

    const cancelBtn = e.target.closest('.btn-cancel-edit');
    if (cancelBtn) {
        const entry = cancelBtn.closest('.feedback-entry');
        if (!entry) return;
        entry.querySelector('.feedback-edit').hidden = true;
        entry.querySelector('.feedback-view').hidden = false;
    }
});