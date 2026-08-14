document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('equipPage');
    if (!page) return;

    const dataUrl = page.dataset.equipUrl;
    const updateBase = page.dataset.equipUpdateBase;
    const csrfToken = page.dataset.csrf;
    const dateInput = document.getElementById('equipDate');
    const tableBody = document.getElementById('equipTableBody');
    const emptyEl = document.getElementById('equipEmpty');
    const errorEl = document.getElementById('equipError');

    const editModal = document.getElementById('equipEditModal');
    const editForm = document.getElementById('equipEditForm');
    const editError = document.getElementById('equipEditError');
    const editSubmitBtn = document.getElementById('equipEditSubmit');
    const editIdInput = document.getElementById('edit_id');
    const editNameInput = document.getElementById('edit_name');
    const editCategoryInput = document.getElementById('edit_category');
    const editPriceInput = document.getElementById('edit_price');
    const editStockInput = document.getElementById('edit_stock');
    const editStatusSelect = document.getElementById('edit_status');

    let currentRows = [];

    function openEditModal(item) {
        editIdInput.value = item.id;
        editNameInput.value = item.name;
        editCategoryInput.value = item.category || '';
        editPriceInput.value = item.price ?? '';
        editStockInput.value = item.stock_total;
        editStatusSelect.value = item.status;
        editError.hidden = true;
        editModal.classList.add('open');
    }

    function closeEditModal() {
        editModal.classList.remove('open');
    }

    document.getElementById('equipEditClose')?.addEventListener('click', closeEditModal);
    editModal.addEventListener('click', (e) => {
        if (e.target === editModal) closeEditModal();
    });

    editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        editError.hidden = true;
        editSubmitBtn.disabled = true;
        const originalLabel = editSubmitBtn.textContent;
        editSubmitBtn.textContent = 'Saving…';

        try {
            const id = editIdInput.value;
            const res = await fetch(`${updateBase}/${id}`, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    name: editNameInput.value.trim(),
                    category: editCategoryInput.value.trim() || null,
                    price: editPriceInput.value,
                    stock_total: editStockInput.value,
                    status: editStatusSelect.value,
                }),
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                editError.textContent = firstError || data.message || 'Could not save changes.';
                editError.hidden = false;
                return;
            }

            closeEditModal();
            loadAvailability(dateInput.value);
        } catch (err) {
            console.error(err);
            editError.textContent = 'Could not save changes. Please try again.';
            editError.hidden = false;
        } finally {
            editSubmitBtn.disabled = false;
            editSubmitBtn.textContent = originalLabel;
        }
    });

    tableBody.addEventListener('click', (e) => {
        const editBtn = e.target.closest('.equip-edit-btn');
        if (editBtn) {
            const item = currentRows.find((r) => String(r.id) === editBtn.dataset.id);
            if (item) openEditModal(item);
            return;
        }

        const deleteBtn = e.target.closest('.equip-delete-btn');
        if (deleteBtn) {
            const item = currentRows.find((r) => String(r.id) === deleteBtn.dataset.id);
            if (item) openDeleteModal(item);
        }
    });

    const deleteModal = document.getElementById('equipDeleteModal');
    const deleteForm = document.getElementById('equipDeleteForm');
    const deleteError = document.getElementById('equipDeleteError');
    const deleteSubmitBtn = document.getElementById('equipDeleteSubmit');
    const deleteNameEl = document.getElementById('equipDeleteName');
    let pendingDeleteId = null;

    function openDeleteModal(item) {
        pendingDeleteId = item.id;
        if (deleteNameEl) deleteNameEl.textContent = item.name;
        deleteError.hidden = true;
        deleteModal.classList.add('open');
    }

    function closeDeleteModal() {
        deleteModal.classList.remove('open');
        pendingDeleteId = null;
    }

    document.getElementById('equipDeleteClose')?.addEventListener('click', closeDeleteModal);
    deleteModal?.addEventListener('click', (e) => {
        if (e.target === deleteModal) closeDeleteModal();
    });

    deleteForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!pendingDeleteId) return;

        deleteError.hidden = true;
        deleteSubmitBtn.disabled = true;
        const originalLabel = deleteSubmitBtn.textContent;
        deleteSubmitBtn.textContent = 'Deleting…';

        try {
            const res = await fetch(`${updateBase}/${pendingDeleteId}`, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                deleteError.textContent = firstError || data.message || 'Could not delete equipment.';
                deleteError.hidden = false;
                return;
            }

            closeDeleteModal();
            loadAvailability(dateInput.value);
        } catch (err) {
            console.error(err);
            deleteError.textContent = 'Could not delete equipment. Please try again.';
            deleteError.hidden = false;
        } finally {
            deleteSubmitBtn.disabled = false;
            deleteSubmitBtn.textContent = originalLabel;
        }
    });

    function todayStr() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    dateInput.value = todayStr();

    function setLoading(isLoading) {
        page.classList.toggle('is-loading', isLoading);
    }

    function setError(show) {
        if (errorEl) errorEl.hidden = !show;
    }

    function availabilityClass(available) {
        if (available <= 0) return 'equip-available-none';
        if (available <= 2) return 'equip-available-low';
        return 'equip-available-ok';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function skeletonRows(count) {
        return Array.from({ length: count }, () => `
            <tr class="equip-skeleton-row">
                <td><span class="skeleton"></span></td>
                <td><span class="skeleton"></span></td>
                <td><span class="skeleton"></span></td>
                <td><span class="skeleton"></span></td>
                <td><span class="skeleton"></span></td>
                <td><span class="skeleton"></span></td>
                <td><span class="skeleton"></span></td>
            </tr>
        `).join('');
    }

    function formatPrice(price) {
        const n = Number(price);
        return Number.isFinite(n) ? `₱${n.toFixed(2)}` : '—';
    }

    function renderRows(rows) {
        currentRows = rows;
        emptyEl.hidden = rows.length > 0;

        if (rows.length === 0) {
            tableBody.innerHTML = '';
            return;
        }

        tableBody.innerHTML = rows.map((item) => `
            <tr>
                <td class="equip-item-name">${escapeHtml(item.name)}</td>
                <td><span class="equip-category-pill">${escapeHtml(item.category || '—')}</span></td>
                <td>${formatPrice(item.price)}</td>
                <td>${item.stock_total}</td>
                <td>${item.reserved_peak}</td>
                <td><span class="equip-available-badge ${availabilityClass(item.available_today)}">${item.available_today}</span></td>
                <td>
                    <button type="button" class="equip-edit-btn" data-id="${item.id}">Edit</button>
                    <button type="button" class="equip-delete-btn" data-id="${item.id}">Delete</button>
                </td>
            </tr>
        `).join('');
    }

    async function loadAvailability(date) {
        setError(false);
        setLoading(true);
        tableBody.innerHTML = skeletonRows(4);

        try {
            const url = `${dataUrl}?date=${encodeURIComponent(date)}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                const body = await res.text().catch(() => '');
                throw new Error(`Failed to load availability (${res.status}): ${body.slice(0, 500)}`);
            }
            const data = await res.json();
            renderRows(data.equipment || []);
        } catch (err) {
            console.error(err);
            setError(true);
            tableBody.innerHTML = '';
        } finally {
            setLoading(false);
        }
    }

    dateInput.addEventListener('change', () => {
        loadAvailability(dateInput.value || todayStr());
    });

    loadAvailability(dateInput.value);
});