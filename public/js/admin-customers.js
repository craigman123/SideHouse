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

    // Small helper for building an element with a class + text content in
    // one line, since almost every cell/row piece below is just that.
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function setLoading(isLoading) {
        page.classList.toggle('is-loading', isLoading);
    }

    function setError(show) {
        if (errorEl) errorEl.hidden = !show;
    }

    // ---------- Table rendering ----------
    // Built entirely with createElement/textContent rather than innerHTML
    // template strings. This is an admin-only view, but every field here
    // (name, email, phone) is still raw customer-entered data, so there's
    // no HTML-injection sink left to reason about at all, regardless of
    // what a static scanner can or can't verify about escaping.

    function buildCustomerRow(c) {
        const tr = document.createElement('tr');
        tr.dataset.key = c.key;

        const typeLabel = c.type === 'registered' ? 'Registered' : 'Guest';
        const typeClass = c.type === 'registered' ? 'type-registered' : 'type-guest';

        // Rank
        const rankTd = el('td', 'col-rank', String(Number(c.rank) || 0));
        rankTd.setAttribute('data-label', '#');
        tr.appendChild(rankTd);

        // Customer (name)
        const nameTd = document.createElement('td');
        nameTd.setAttribute('data-label', 'Customer');
        const nameCell = el('div', 'customer-name-cell');
        nameCell.appendChild(el('span', 'name', c.name));
        nameTd.appendChild(nameCell);
        tr.appendChild(nameTd);

        // Contact (email / phone)
        const contactTd = document.createElement('td');
        contactTd.setAttribute('data-label', 'Contact');
        const contactCell = el('div', 'customer-contact-cell');
        if (c.email) contactCell.appendChild(el('span', null, c.email));
        if (c.phone) contactCell.appendChild(el('span', null, c.phone));
        if (!c.email && !c.phone) contactCell.appendChild(el('span', null, '—'));
        contactTd.appendChild(contactCell);
        tr.appendChild(contactTd);

        // Bookings count
        const bookingsTd = el('td', 'col-num', c.bookings_count.toLocaleString());
        bookingsTd.setAttribute('data-label', 'Bookings');
        tr.appendChild(bookingsTd);

        // Total spent
        const spentTd = el('td', 'col-num', formatCurrency(c.total_spent));
        spentTd.setAttribute('data-label', 'Total Spent');
        tr.appendChild(spentTd);

        // Last booking date
        const lastBookingTd = el('td', null, formatDate(c.last_booking_date));
        lastBookingTd.setAttribute('data-label', 'Last Booking');
        tr.appendChild(lastBookingTd);

        // Type pill
        const typeTd = document.createElement('td');
        typeTd.className = 'col-type';
        typeTd.setAttribute('data-label', 'Type');
        typeTd.appendChild(el('span', `customer-type-pill ${typeClass}`, typeLabel));
        tr.appendChild(typeTd);

        tr.addEventListener('click', () => openCustomerModal(c));
        return tr;
    }

    function renderTable(customers) {
        tableBody.innerHTML = '';

        if (!customers.length) {
            emptyEl.hidden = false;
            return;
        }
        emptyEl.hidden = true;

        customers.forEach((c) => {
            tableBody.appendChild(buildCustomerRow(c));
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

    function buildBookingRow(b) {
        const statusClass = b.status === 'paid' ? 'status-paid'
            : b.status === 'cancelled' ? 'status-cancelled'
            : 'status-pending';
        const statusLabel = b.status.charAt(0).toUpperCase() + b.status.slice(1);

        const row = el('div', 'customer-booking-row');

        const main = el('div', 'customer-booking-main');
        main.appendChild(el('span', 'customer-booking-court', b.court));
        const datetime = el('span', 'customer-booking-datetime');
        datetime.append(`${b.date} \u00b7 ${b.time}`);
        main.appendChild(datetime);
        row.appendChild(main);

        const side = el('div', 'customer-booking-side');
        side.appendChild(el('span', 'customer-booking-amount', formatCurrency(b.amount)));
        side.appendChild(el('span', `status ${statusClass}`, statusLabel));
        row.appendChild(side);

        return row;
    }

    function openCustomerModal(customer) {
        modalLabel.textContent = `${customer.name} — Bookings`;
        modalList.innerHTML = '';

        customer.bookings.forEach((b) => {
            modalList.appendChild(buildBookingRow(b));
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