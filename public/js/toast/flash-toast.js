document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('flashData');
    if (!box) return;

    var errorMsg = box.dataset.error;
    var successMsg = box.dataset.success;

    if (errorMsg) showToast(errorMsg, 'error');
    if (successMsg) showToast(successMsg, 'success');
});