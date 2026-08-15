document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('customersPage');
    if (!page) return;

    const customersUrl = page.dataset.customersUrl;
    const tableBody = document.getElementById('customersTableBody');
    const searchInput = document.getElementById('customerSearch');
    const emptyEl = document.getElementById('customersEmpty');
    const errorEl = document.getElementById('customersError');

    const modal = document.getElementById('customerBookingsModal');
    const modalClose = document.getElementById('customerBookingsModalClose');
    const modalLabel = document.getElementById('customerBookingsModalLabel');
    const modalList = document.getElementById('customerBookingsList');

    let allCustomers = [];

    function formatCurrency(n) {
        const value = Number(n) || 0;
        return `\u20b1${value.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr + 'T00:00:00');
        if (Number.isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function setLoading(isLoading) {
        page.classList.toggle('is-loading', isLoading);
    }

    function setError(show) {
        if (errorEl) errorEl.hidden = !show;
    }

    // ---------- Table rendering ----------

    function renderTable(customers) {
        tableBody.innerHTML = '';

        if (!customers.length) {
            emptyEl.hidden = false;
            return;
        }
        emptyEl.hidden = true;

        customers.forEach((c) => {
            const tr = document.createElement('tr');
            tr.dataset.key = c.key;

            const typeLabel = c.type === 'registered' ? 'Registered' : 'Guest';
            const typeClass = c.type === 'registered' ? 'type-registered' : 'type-guest';

            tr.innerHTML = `
                <td class="col-rank" data-label="#">${c.rank}</td>
                <td data-label="Customer">
                    <div class="customer-name-cell">
                        <span class="name">${escapeHtml(c.name)}</span>
                    </div>
                </td>
                <td data-label="Contact">
                    <div class="customer-contact-cell">
                        ${c.email ? `<span>${escapeHtml(c.email)}</span>` : ''}
                        ${c.phone ? `<span>${escapeHtml(c.phone)}</span>` : ''}
                        ${!c.email && !c.phone ? '<span>—</span>' : ''}
                    </div>
                </td>
                <td class="col-num" data-label="Bookings">${c.bookings_count.toLocaleString()}</td>
                <td class="col-num" data-label="Total Spent">${formatCurrency(c.total_spent)}</td>
                <td data-label="Last Booking">${formatDate(c.last_booking_date)}</td>
                <td class="col-type" data-label="Type"><span class="customer-type-pill ${typeClass}">${typeLabel}</span></td>
            `;

            tr.addEventListener('click', () => openCustomerModal(c));
            tableBody.appendChild(tr);
        });
    }

    function applyFilter() {
        const q = (searchInput.value || '').trim().toLowerCase();
        if (!q) {
            renderTable(allCustomers);
            return;
        }
        const filtered = allCustomers.filter((c) => {
            return (c.name || '').toLowerCase().includes(q)
                || (c.email || '').toLowerCase().includes(q)
                || (c.phone || '').toLowerCase().includes(q);
        });
        renderTable(filtered);
    }

    // ---------- Booking history modal ----------

    function openCustomerModal(customer) {
        modalLabel.textContent = `${customer.name} — Bookings`;
        modalList.innerHTML = '';

        customer.bookings.forEach((b) => {
            const statusClass = b.status === 'paid' ? 'status-paid'
                : b.status === 'cancelled' ? 'status-cancelled'
                : 'status-pending';

            const row = document.createElement('div');
            row.className = 'customer-booking-row';
            row.innerHTML = `
                <div class="customer-booking-main">
                    <span class="customer-booking-court">${escapeHtml(b.court)}</span>
                    <span class="customer-booking-datetime">${escapeHtml(b.date)} &middot; ${escapeHtml(b.time)}</span>
                </div>
                <div class="customer-booking-side">
                    <span class="customer-booking-amount">${formatCurrency(b.amount)}</span>
                    <span class="status ${statusClass}">${escapeHtml(b.status.charAt(0).toUpperCase() + b.status.slice(1))}</span>
                </div>
            `;
            modalList.appendChild(row);
        });

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeCustomerModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (modalClose) modalClose.addEventListener('click', closeCustomerModal);
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeCustomerModal();
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && !modal.hidden) closeCustomerModal();
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }

    // ---------- Load ----------

    async function loadCustomers() {
        setError(false);
        setLoading(true);
        try {
            const res = await fetch(customersUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`Failed to load customers (${res.status})`);
            const data = await res.json();
            allCustomers = data.customers || [];
            applyFilter();
        } catch (err) {
            console.error(err);
            setError(true);
        } finally {
            setLoading(false);
        }
    }

    loadCustomers();
});