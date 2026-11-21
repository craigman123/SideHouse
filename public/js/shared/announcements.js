document.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('flash-data');
    const toastContainer = document.getElementById('toastContainer');

    const modal = document.getElementById('announcementModal');
    const openBtn = document.getElementById('openAnnouncementModal');
    const closeBtn = document.getElementById('closeAnnouncementModal');
    const cancelBtn = document.getElementById('cancelAnnouncementModal');

    const confirmModal = document.getElementById('confirmSendModal');
    const confirmSendBtn = document.getElementById('confirmSendBtn');
    const cancelSendConfirm = document.getElementById('cancelSendConfirm');

    const deleteModal = document.getElementById('confirmDeleteModal');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const cancelDeleteConfirm = document.getElementById('cancelDeleteConfirm');

    const form = document.getElementById('announcementForm');
    const sendBtn = document.getElementById('sendAnnouncementBtn');
    const titleInput = document.getElementById('title');
    const bodyInput = document.getElementById('body');
    const titleError = document.getElementById('titleError');
    const bodyError = document.getElementById('bodyError');
    const storeUrl = form?.dataset.storeUrl;

    // Set by openDeleteConfirm() right before the modal opens, read by
    // confirmDeleteBtn's click handler — simplest way to pass "which row"
    // through a modal that's shared by every delete button in the table.
    let pendingDeleteUrl = null;
    let pendingDeleteRow = null;

    function csrfToken() {
        return form?.querySelector('input[name="_token"]')?.value
            || document.querySelector('meta[name="csrf-token"]')?.content
            || '';
    }

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

    // ---------- Compose modal ----------

    function openModal(reset = true) {
        clearErrors();
        if (reset) form.reset();
        modal.classList.add('open');
        titleInput.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    openBtn?.addEventListener('click', () => openModal(true));
    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    // ---------- Confirm-send modal ----------
    // Stacked on top of the compose modal instead of a native confirm() —
    // same overlay/box styling so it doesn't feel like a browser popup.

    function openConfirm() {
        confirmModal.classList.add('open');
    }

    function closeConfirm() {
        confirmModal.classList.remove('open');
    }

    cancelSendConfirm?.addEventListener('click', closeConfirm);
    confirmModal?.addEventListener('click', (e) => {
        if (e.target === confirmModal) closeConfirm();
    });

    // ---------- Confirm-delete modal ----------

    function openDeleteConfirm(url, row) {
        pendingDeleteUrl = url;
        pendingDeleteRow = row;
        deleteModal.classList.add('open');
    }

    function closeDeleteConfirm() {
        deleteModal.classList.remove('open');
        pendingDeleteUrl = null;
        pendingDeleteRow = null;
    }

    cancelDeleteConfirm?.addEventListener('click', closeDeleteConfirm);
    deleteModal?.addEventListener('click', (e) => {
        if (e.target === deleteModal) closeDeleteConfirm();
    });

    // Delegated on the table body rather than bound per-button, so this
    // keeps working for rows prependAnnouncement() adds later without
    // needing to re-wire anything after a successful send.
    document.getElementById('announcementsTableBody')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.row-delete-btn');
        if (!btn) return;

        const row = btn.closest('tr');
        openDeleteConfirm(btn.dataset.deleteUrl, row);
    });

    confirmDeleteBtn?.addEventListener('click', async () => {
        if (!pendingDeleteUrl || !pendingDeleteRow) return;

        const url = pendingDeleteUrl;
        const row = pendingDeleteRow;
        const rowDeleteBtn = row.querySelector('.row-delete-btn');

        closeDeleteConfirm();
        confirmDeleteBtn.disabled = true;
        if (rowDeleteBtn) rowDeleteBtn.disabled = true;

        try {
            const res = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                showToast(data.message || 'Could not delete this announcement.', 'error');
                if (rowDeleteBtn) rowDeleteBtn.disabled = false;
                return;
            }

            row.remove();
            showToast(data.message || 'Announcement deleted.', 'success');

            const tbody = document.getElementById('announcementsTableBody');
            if (tbody && tbody.children.length === 0) {
                document.getElementById('announcementsListWrap').innerHTML =
                    '<p class="modal-text" id="noAnnouncementsMsg">No announcements have been sent yet.</p>';
            }
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
            if (rowDeleteBtn) rowDeleteBtn.disabled = false;
        } finally {
            confirmDeleteBtn.disabled = false;
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (deleteModal?.classList.contains('open')) { closeDeleteConfirm(); return; }
        if (confirmModal?.classList.contains('open')) { closeConfirm(); return; }
        if (modal?.classList.contains('open')) closeModal();
    });

    function prependAnnouncement(item) {
        let table = document.getElementById('announcementsTable');

        if (!table) {
            document.getElementById('announcementsListWrap').innerHTML = `
                <table class="data-table" style="width: 100%;" id="announcementsTable">
                    <thead>
                        <tr><th>Title</th><th>Message</th><th>Sent</th><th></th></tr>
                    </thead>
                    <tbody id="announcementsTableBody"></tbody>
                </table>
            `;
            table = document.getElementById('announcementsTable');
        }

        const tbody = document.getElementById('announcementsTableBody');
        const tr = document.createElement('tr');
        tr.dataset.announcementId = item.id;

        const titleTd = document.createElement('td');
        const bodyTd = document.createElement('td');
        const sentTd = document.createElement('td');
        const actionsTd = document.createElement('td');

        titleTd.textContent = item.title;
        bodyTd.textContent = item.body.length > 80 ? `${item.body.slice(0, 80)}…` : item.body;
        sentTd.textContent = item.created_at;

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'row-delete-btn';
        deleteBtn.dataset.deleteUrl = item.delete_url;
        deleteBtn.setAttribute('aria-label', 'Delete announcement');
        deleteBtn.textContent = 'Delete';
        actionsTd.appendChild(deleteBtn);

        tr.append(titleTd, bodyTd, sentTd, actionsTd);
        tbody.prepend(tr);
    }

    // Submitting the form no longer sends anything directly — it just
    // runs native validation, then opens the confirm modal. The actual
    // POST happens when "Send to All Users" is clicked on that modal.
    form?.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!form.reportValidity()) return;
        openConfirm();
    });

    confirmSendBtn?.addEventListener('click', async () => {
        closeConfirm();
        clearErrors();
        sendBtn.disabled = true;
        const originalLabel = sendBtn.textContent;
        sendBtn.textContent = 'Sending…';

        try {
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: new FormData(form),
            });

            const data = await res.json().catch(() => ({}));

            if (res.status === 422 && data.errors) {
                // Reopen the compose modal (without wiping what was typed)
                // so the user can see and fix what's wrong.
                openModal(false);
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
