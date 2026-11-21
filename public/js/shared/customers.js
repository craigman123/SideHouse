document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('customersPage');
    if (!page) return;

    const customersUrl = page.dataset.customersUrl;
    const tableBody = document.getElementById('customersTableBody');
    const customersTable = page.querySelector('.customers-table');
    const searchInput = document.getElementById('customerSearch');
    const typeFilter = document.getElementById('customerTypeFilter');
    const typeOptions = Array.from(typeFilter?.querySelectorAll('[data-customer-type]') || []);
    const typeCounts = Array.from(page.querySelectorAll('[data-customer-type-count]'));
    const emptyEl = document.getElementById('customersEmpty');
    const emptyMessage = document.getElementById('customersEmptyMessage');
    const clearFiltersButton = document.getElementById('customerFiltersClear');
    const errorEl = document.getElementById('customersError');

    const modal = document.getElementById('customerBookingsModal');
    const modalClose = document.getElementById('customerBookingsModalClose');
    const modalLabel = document.getElementById('customerBookingsModalLabel');
    const modalList = document.getElementById('customerBookingsList');

    let allCustomers = [];
    const allowedTypes = new Set(['registered', 'guest']);
    const urlType = new URLSearchParams(window.location.search).get('type');
    let activeType = allowedTypes.has(urlType) ? urlType : 'all';

    function updateTypeUrl(type) {
        const url = new URL(window.location.href);
        if (type === 'all') url.searchParams.delete('type');
        else url.searchParams.set('type', type);
        history.replaceState(history.state, '', `${url.pathname}${url.search}${url.hash}`);
    }

    function updateTypeOptions() {
        typeOptions.forEach((option) => {
            const selected = option.dataset.customerType === activeType;
            option.setAttribute('aria-checked', String(selected));
            option.tabIndex = selected ? 0 : -1;
            option.classList.toggle('is-active', selected);
        });
    }

    function setActiveType(type, { updateUrl = true } = {}) {
        activeType = allowedTypes.has(type) ? type : 'all';
        updateTypeOptions();
        if (updateUrl) updateTypeUrl(activeType);
        applyFilter();
    }

    function updateTypeCounts() {
        const counts = {
            all: allCustomers.length,
            registered: allCustomers.filter((customer) => customer.type === 'registered').length,
            guest: allCustomers.filter((customer) => customer.type === 'guest').length,
        };
        typeCounts.forEach((count) => {
            count.textContent = `(${counts[count.dataset.customerTypeCount] || 0})`;
        });
    }

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
            customersTable.hidden = true;
            emptyEl.hidden = false;
            return;
        }
        customersTable.hidden = false;
        emptyEl.hidden = true;

        customers.forEach((c, index) => {
            tableBody.appendChild(buildCustomerRow({ ...c, rank: index + 1 }));
        });
    }

    function applyFilter() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const filtered = allCustomers.filter((c) => {
            const matchesType = activeType === 'all' || c.type === activeType;
            const matchesSearch = !q || (c.name || '').toLowerCase().includes(q)
                || (c.email || '').toLowerCase().includes(q)
                || (c.phone || '').toLowerCase().includes(q);
            return matchesType && matchesSearch;
        });
        if (!filtered.length) {
            const typeName = activeType === 'guest' ? 'guest' : activeType === 'registered' ? 'registered' : 'customer';
            emptyMessage.textContent = q
                ? `No ${typeName} customers match your search.`
                : `No ${typeName} customers found.`;
        }
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

    typeOptions.forEach((option) => {
        option.addEventListener('click', () => setActiveType(option.dataset.customerType));
        option.addEventListener('keydown', (event) => {
            const currentIndex = typeOptions.indexOf(option);
            let nextIndex = null;
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % typeOptions.length;
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + typeOptions.length) % typeOptions.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = typeOptions.length - 1;
            if (nextIndex === null) return;
            event.preventDefault();
            setActiveType(typeOptions[nextIndex].dataset.customerType);
            typeOptions[nextIndex].focus();
        });
    });
    clearFiltersButton?.addEventListener('click', () => {
        searchInput.value = '';
        setActiveType('all');
        typeOptions.find((option) => option.dataset.customerType === 'all')?.focus();
    });

    updateTypeOptions();
    if (urlType && !allowedTypes.has(urlType)) updateTypeUrl('all');

    // ---------- Load ----------

    async function loadCustomers() {
        setError(false);
        setLoading(true);
        try {
            const res = await fetch(customersUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`Failed to load customers (${res.status})`);
            const data = await res.json();
            allCustomers = data.customers || [];
            updateTypeCounts();
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
