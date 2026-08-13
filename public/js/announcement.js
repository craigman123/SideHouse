document.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');
    const modal = document.getElementById('announcementModal');
    const openBtn = document.getElementById('openAnnouncementModal');
    const closeBtn = document.getElementById('closeAnnouncementModal');
    const cancelBtn = document.getElementById('cancelAnnouncementModal');
    const form = document.getElementById('announcementForm');
    const sendBtn = document.getElementById('sendAnnouncementBtn');
    const titleInput = document.getElementById('title');
    const bodyInput = document.getElementById('body');
    const titleError = document.getElementById('titleError');
    const bodyError = document.getElementById('bodyError');
    const storeUrl = "{{ route('admin.announcements.store') }}";

    function showToast(message, type = 'success') {
        if (!toastContainer) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `<span class="toast-message"></span><button type="button" class="toast-close" aria-label="Dismiss">&times;</button>`;
        toast.querySelector('.toast-message').textContent = message;
        toastContainer.appendChild(toast);
        const remove = () => { toast.classList.add('toast-out'); setTimeout(() => toast.remove(), 300); };
        toast.querySelector('.toast-close').addEventListener('click', remove);
        setTimeout(remove, 4000);
    }

    if (flash?.dataset.success) showToast(flash.dataset.success, 'success');

    function clearErrors() {
        [titleInput, bodyInput].forEach((el) => el.classList.remove('field-invalid'));
        [titleError, bodyError].forEach((el) => { el.style.display = 'none'; el.textContent = ''; });
    }

    function openModal() {
        clearErrors();
        form.reset();
        modal.classList.add('open');
        titleInput.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    openBtn?.addEventListener('click', openModal);
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    function prependAnnouncement(item) {
        let table = document.getElementById('announcementsTable');

        if (!table) {
            document.getElementById('announcementsListWrap').innerHTML = `
                <table class="data-table" style="width: 100%;" id="announcementsTable">
                    <thead>
                        <tr><th>Title</th><th>Message</th><th>Sent</th></tr>
                    </thead>
                    <tbody id="announcementsTableBody"></tbody>
                </table>
            `;
            table = document.getElementById('announcementsTable');
        }

        const tbody = document.getElementById('announcementsTableBody');
        const tr = document.createElement('tr');
        const titleTd = document.createElement('td');
        const bodyTd = document.createElement('td');
        const sentTd = document.createElement('td');

        titleTd.textContent = item.title;
        bodyTd.textContent = item.body.length > 80 ? `${item.body.slice(0, 80)}…` : item.body;
        sentTd.textContent = item.created_at;

        tr.append(titleTd, bodyTd, sentTd);
        tbody.prepend(tr);
    }

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!confirm('Send this announcement to every user? This can\'t be undone.')) {
            return;
        }

        clearErrors();
        sendBtn.disabled = true;
        const originalLabel = sendBtn.textContent;
        sendBtn.textContent = 'Sending…';

        try {
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                },
                body: new FormData(form),
            });

            const data = await res.json().catch(() => ({}));

            if (res.status === 422 && data.errors) {
                if (data.errors.title) {
                    titleInput.classList.add('field-invalid');
                    titleError.textContent = data.errors.title[0];
                    titleError.style.display = 'block';
                }
                if (data.errors.body) {
                    bodyInput.classList.add('field-invalid');
                    bodyError.textContent = data.errors.body[0];
                    bodyError.style.display = 'block';
                }
                return;
            }

            if (!res.ok) {
                showToast(data.message || 'Something went wrong. Please try again.', 'error');
                return;
            }

            closeModal();
            document.getElementById('noAnnouncementsMsg')?.remove();
            prependAnnouncement(data.announcement);
            showToast(data.message, 'success');
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
        } finally {
            sendBtn.disabled = false;
            sendBtn.textContent = originalLabel;
        }
    });
});