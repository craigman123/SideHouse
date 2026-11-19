document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('form');
    var submitBtn = document.getElementById('registerSubmitBtn');
    if (!form || !submitBtn) return;

    form.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating account…';
    });
});
