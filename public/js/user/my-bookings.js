(function () {
    const list = document.querySelector('.my-bookings-page .table-responsive');
    const skeleton = document.querySelector('.my-bookings-page .bookings-skeleton');

    if (!list) return;

    list.setAttribute('aria-busy', 'false');
    if (skeleton) skeleton.hidden = true;

    function updateCountdowns() {
        document.querySelectorAll('.my-bookings-page .booking-countdown[data-expires-at]').forEach(function (countdown) {
            const expiresAt = Date.parse(countdown.dataset.expiresAt);
            const seconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
            const value = countdown.querySelector('strong');

            if (seconds > 0) {
                value.textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
                return;
            }

            const row = countdown.closest('.booking-row');
            const status = row && row.querySelector('.status');
            const actions = row && row.querySelector('.actions-cell');
            const bookingAgainUrl = row && row.dataset.bookAgainUrl;

            countdown.remove();
            if (status) {
                status.className = 'status status-expired';
                status.textContent = 'Expired';
            }
            if (actions) {
                actions.querySelectorAll('[data-cancel], .booking-pay-action').forEach(function (control) {
                    control.remove();
                });
                if (bookingAgainUrl && !actions.querySelector('.booking-retry-action')) {
                    const link = document.createElement('a');
                    link.className = 'booking-action booking-retry-action';
                    link.href = bookingAgainUrl;
                    link.textContent = 'Book again';
                    actions.appendChild(link);
                }
            }
        });
    }

    updateCountdowns();
    window.setInterval(updateCountdowns, 1000);
})();
