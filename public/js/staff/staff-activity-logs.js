(() => {
    const root = document.querySelector('[data-staff-activity-logs]');
    if (!root) return;

    const form = root.querySelector('[data-activity-filters]');
    const body = root.querySelector('[data-activity-rows]');
    const error = root.querySelector('[data-activity-error]');
    const retry = root.querySelector('[data-activity-retry]');
    const pagination = root.querySelector('[data-activity-pagination]');
    const count = root.querySelector('[data-activity-count]');
    const total = root.querySelector('[data-activity-total]');
    let requestUrl = new URL(root.dataset.dataUrl, window.location.origin);

    const escapeText = (value) => String(value ?? '');
    const displayDate = (value) => {
        if (!value) return '—';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(undefined, {
            day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit',
        }).format(date);
    };

    function renderRows(rows) {
        body.replaceChildren();
        if (!rows.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'empty-row';
            td.textContent = 'No activity recorded yet.';
            tr.append(td);
            body.append(tr);
            return;
        }
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            const values = [displayDate(row.created_at), escapeText(row.user_name || 'Guest'), escapeText(row.action), escapeText(row.description), row.subject_type ? `${escapeText(row.subject_type)}${row.subject_id ? ` #${escapeText(row.subject_id)}` : ''}` : '—'];
            values.forEach((value, index) => {
                const td = document.createElement('td');
                if (index === 0 || index === 4) td.className = 'cell-muted';
                if (index === 1) td.className = 'cell-name';
                if (index === 2) {
                    const code = document.createElement('code');
                    code.textContent = value;
                    td.append(code);
                } else td.textContent = value;
                tr.append(td);
            });
            body.append(tr);
        });
    }

    function renderPagination(meta) {
        pagination.replaceChildren();
        if (meta.last_page <= 1) return;
        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Activity log pages');
        const group = document.createElement('div');
        const add = (label, page, disabled, current = false) => {
            const el = document.createElement(disabled || current ? 'span' : 'a');
            el.textContent = label;
            if (current) el.setAttribute('aria-current', 'page');
            if (!disabled && !current) {
                el.href = '#';
                el.dataset.page = String(page);
                el.addEventListener('click', (event) => {
                    event.preventDefault();
                    load(page);
                });
            }
            group.append(el);
        };
        add('Previous', meta.current_page - 1, meta.current_page <= 1);
        for (let page = 1; page <= meta.last_page; page += 1) add(String(page), page, false, page === meta.current_page);
        add('Next', meta.current_page + 1, meta.current_page >= meta.last_page);
        nav.append(group);
        pagination.append(nav);
    }

    async function load(page = 1) {
        const url = new URL(requestUrl);
        url.searchParams.set('page', String(page));
        error.hidden = true;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Unable to load activity logs');
            const payload = await response.json();
            renderRows(payload.data || []);
            renderPagination(payload.pagination);
            total.textContent = String(payload.pagination.total);
            count.textContent = `Showing ${payload.pagination.from ?? 0} to ${payload.pagination.to ?? 0} of ${payload.pagination.total} results`;
            window.history.replaceState({}, '', `${window.location.pathname}${url.search}`);
        } catch (_) {
            error.hidden = false;
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        requestUrl = new URL(root.dataset.dataUrl, window.location.origin);
        new FormData(form).forEach((value, key) => {
            if (String(value).trim()) requestUrl.searchParams.set(key, String(value).trim());
        });
        load(1);
    });
    retry.addEventListener('click', () => load(Number(new URLSearchParams(window.location.search).get('page')) || 1));
    const params = new URLSearchParams(window.location.search);
    ['user', 'action'].forEach((key) => {
        if (params.has(key)) form.elements[key].value = params.get(key);
    });
    requestUrl = new URL(root.dataset.dataUrl, window.location.origin);
    ['user', 'action'].forEach((key) => {
        if (params.has(key)) requestUrl.searchParams.set(key, params.get(key));
    });
    load(Number(params.get('page')) || 1);
})();
