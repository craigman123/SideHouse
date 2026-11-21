(() => {
    const page = document.getElementById('userAccessPage');
    if (!page) return;

    const listUrl = page.dataset.listUrl;
    const candidatesUrl = page.dataset.candidatesUrl;
    const createUrl = page.dataset.createUrl;
    const actorRole = page.dataset.actorRole;
    window.userAccessCurrentId = page.dataset.currentId;
    const ownerAtRender = page.dataset.ownerExists === 'true';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const tableBody = document.getElementById('userAccessTableBody');
    const tableWrap = document.getElementById('userAccessTableWrap');
    const cards = document.getElementById('userAccessCards');
    const loading = document.getElementById('userAccessLoading');
    const empty = document.getElementById('userAccessEmpty');
    const error = document.getElementById('userAccessError');
    const summary = document.getElementById('userAccessSummary');
    const ownerBanner = document.getElementById('ownerBootstrapBanner');
    const search = document.getElementById('userAccessSearch');
    const roleFilter = document.getElementById('userAccessRoleFilter');
    const accessDialog = document.getElementById('accessDialog');
    const accessForm = document.getElementById('accessForm');
    const removeDialog = document.getElementById('removeAccessDialog');
    const removeForm = document.getElementById('removeAccessForm');
    const roleSelect = document.getElementById('accessRole');
    const permissionFieldset = document.getElementById('permissionFieldset');
    const permissionList = document.getElementById('permissionToggleList');
    const permissionHint = document.getElementById('permissionCustomizedHint');
    const continueButton = document.getElementById('continueAccess');
    const saveButton = document.getElementById('saveAccess');
    const detailStep = document.getElementById('accessDetailsStep');
    const passwordStep = document.getElementById('passwordConfirmStep');
    const passwordInput = document.getElementById('accessPassword');
    const dialogError = document.getElementById('accessDialogError');
    const candidatePicker = document.getElementById('candidatePicker');
    const candidateSearch = document.getElementById('candidateSearch');
    const candidateResults = document.getElementById('candidateResults');
    const selectedCandidateNode = document.getElementById('selectedCandidate');
    const configNode = document.getElementById('userAccessConfig');

    let config = { permissions: {}, staffPreset: [] };
    try { config = JSON.parse(configNode?.textContent || '{}'); } catch (_) { /* use empty permissions */ }

    const state = {
        users: [],
        allUsers: [],
        selectedUser: null,
        selectedCandidate: null,
        mode: 'create',
        candidateItems: [],
        candidateIndex: -1,
        candidateTimer: null,
        candidateController: null,
        listController: null,
        returnFocus: null,
    };

    const roleNames = { owner: 'Owner', admin: 'Admin', staff: 'Staff' };
    const roleOrder = { owner: 0, admin: 1, staff: 2 };
    const permissionOrder = Object.keys(config.permissions || {});

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function toast(message, type = 'success') {
        const safe = String(message).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[char]));
        if (typeof window.showToast === 'function') window.showToast(safe, type);
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } : {}),
                ...(options.headers || {}),
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            throw new Error(validation || payload.message || `Request failed (${response.status}).`);
        }
        return payload;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '—';
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }

    function currentOwnerCount() {
        return state.allUsers.filter((user) => user.role === 'owner').length || (ownerAtRender ? 1 : 0);
    }

    function mayGrantRole(role) {
        if (role === 'owner') return actorRole === 'owner' && currentOwnerCount() > 0;
        return role === 'staff' || role === 'admin';
    }

    function updateOwnerMessage() {
        ownerBanner.hidden = currentOwnerCount() > 0;
        const ownerOption = roleSelect.querySelector('option[value="owner"]');
        if (ownerOption) {
            ownerOption.disabled = !mayGrantRole('owner');
            ownerOption.title = ownerOption.disabled
                ? (currentOwnerCount() === 0 ? 'Bootstrap the first owner with the command shown above.' : 'Only an owner can grant the owner role.')
                : '';
        }
    }

    function renderSummary(users) {
        const counts = { owner: 0, admin: 0, staff: 0 };
        users.forEach((user) => { if (Object.prototype.hasOwnProperty.call(counts, user.role)) counts[user.role]++; });
        summary.replaceChildren();
        ['owner', 'admin', 'staff'].forEach((role) => {
            const item = element('span', `user-access-count user-access-count--${role}`);
            item.append(element('strong', '', String(counts[role])));
            item.append(document.createTextNode(` ${role}${counts[role] === 1 ? '' : 's'}`));
            summary.append(item);
        });
        updateOwnerMessage();
    }

    function roleBadge(role) {
        return element('span', `role-badge role-badge--${role}`, roleNames[role] || 'Unknown');
    }

    function permissionNames(permissions) {
        return permissionOrder.filter((permission) => permissions?.includes(permission));
    }

    function guardReason(user) {
        const reasons = [];
        if (Number(user.id) === Number(page.dataset.currentId)) reasons.push('You cannot change your own role, permissions, or access.');
        if (user.role === 'owner' && actorRole !== 'owner') reasons.push('Only an owner can manage owner accounts.');
        if (user.role === 'owner' && currentOwnerCount() <= 1) reasons.push('The last owner cannot be demoted or removed.');
        return reasons.join(' ');
    }

    function actionMenu(user) {
        const details = element('details', 'user-access-actions');
        details.dataset.userId = String(user.id);
        const summaryButton = element('summary', '', 'Actions');
        summaryButton.setAttribute('aria-label', `Actions for ${user.name}`);
        const menu = element('div', 'user-access-action-menu');
        const reason = guardReason(user);
        const edit = element('button', '', 'Edit access');
        edit.type = 'button';
        edit.disabled = Boolean(reason);
        edit.title = reason;
        edit.addEventListener('click', () => { details.open = false; openEdit(user, edit); });
        const remove = element('button', 'is-danger', 'Remove access');
        remove.type = 'button';
        remove.disabled = Boolean(reason);
        remove.title = reason;
        remove.addEventListener('click', () => { details.open = false; openRemove(user, remove); });
        menu.append(edit, remove);
        details.append(summaryButton, menu);
        return details;
    }

    function personCell(user) {
        const cell = element('div', 'user-access-person');
        const nameRow = element('div', 'user-access-name-row');
        nameRow.append(element('strong', 'user-access-person-name', user.name || 'Unnamed account'));
        if (Number(user.id) === Number(window.userAccessCurrentId)) nameRow.append(element('span', 'you-badge', 'You'));
        cell.append(nameRow, element('span', 'user-access-username', `@${user.username || '—'}`));
        return cell;
    }

    function renderTableRow(user) {
        const row = document.createElement('tr');
        row.dataset.userId = String(user.id);
        const nameCell = document.createElement('td'); nameCell.append(personCell(user));
        const emailCell = element('td', 'user-access-email', user.email || '—');
        const roleCell = document.createElement('td'); roleCell.append(roleBadge(user.role));
        const mfaCell = document.createElement('td'); mfaCell.append(element('span', `mfa-status ${user.mfa_enabled ? 'is-enabled' : 'is-pending'}`, user.mfa_enabled ? 'Enabled' : 'Pending setup'));
        const lastLoginCell = element('td', 'user-access-date', formatDate(user.last_login_at));
        const promotedCell = document.createElement('td');
        const promoter = user.promoted_by?.name || '—';
        promotedCell.append(element('span', 'user-access-promoter', promoter));
        if (user.promoted_at) promotedCell.append(element('span', 'user-access-date', formatDate(user.promoted_at)));
        const actionsCell = document.createElement('td'); actionsCell.append(actionMenu(user));
        row.append(nameCell, emailCell, roleCell, mfaCell, lastLoginCell, promotedCell, actionsCell);
        return row;
    }

    function renderCard(user) {
        const card = element('article', 'user-access-card');
        card.dataset.userId = String(user.id);
        const header = element('header', 'user-access-card-header');
        header.append(personCell(user), roleBadge(user.role));
        const list = element('dl', 'user-access-card-details');
        const fields = [
            ['Email', user.email || '—'],
            ['MFA', user.mfa_enabled ? 'Enabled' : 'Pending setup'],
            ['Last login', formatDate(user.last_login_at)],
            ['Promoted by', user.promoted_by?.name || '—'],
            ['Promoted date', formatDate(user.promoted_at)],
        ];
        fields.forEach(([label, value]) => list.append(element('dt', '', label), element('dd', '', value)));
        card.append(header, list, actionMenu(user));
        return card;
    }

    function renderUsers(users) {
        state.users = users;
        tableBody.replaceChildren(...users.map(renderTableRow));
        cards.replaceChildren(...users.map(renderCard));
        loading.hidden = true;
        error.hidden = true;
        const hasUsers = users.length > 0;
        empty.hidden = hasUsers;
        tableWrap.hidden = !hasUsers;
        cards.hidden = !hasUsers;
    }

    async function loadUsers({ refreshAll = false } = {}) {
        state.listController?.abort();
        const controller = new AbortController();
        state.listController = controller;
        error.hidden = true;
        empty.hidden = true;
        loading.hidden = false;
        tableWrap.hidden = true;
        cards.hidden = true;
        try {
            const params = new URLSearchParams();
            if (search.value.trim()) params.set('q', search.value.trim());
            if (roleFilter.value !== 'all') params.set('role', roleFilter.value);
            const query = params.toString();
            const payload = await request(`${listUrl}${query ? `?${query}` : ''}`, { signal: controller.signal });
            if (refreshAll || state.allUsers.length === 0) {
                const allPayload = query
                    ? await request(listUrl, { signal: controller.signal })
                    : payload;
                state.allUsers = allPayload.users || [];
                renderSummary(state.allUsers);
            }
            renderUsers(payload.users || []);
        } catch (loadError) {
            if (loadError.name === 'AbortError') return;
            loading.hidden = true;
            tableWrap.hidden = true;
            cards.hidden = true;
            empty.hidden = true;
            error.hidden = false;
        }
    }

    function currentPermissions() {
        return Array.from(permissionList.querySelectorAll('input:checked')).map((input) => input.value);
    }

    function renderPermissions(permissions = [], customized = false) {
        permissionList.replaceChildren();
        const selected = new Set(permissions);
        const groups = new Map();
        permissionOrder.forEach((key) => {
            const definition = config.permissions[key];
            if (!definition) return;
            if (!groups.has(definition.group)) groups.set(definition.group, []);
            groups.get(definition.group).push([key, definition.label]);
        });
        groups.forEach((items, groupName) => {
            const group = element('fieldset', 'permission-group');
            group.append(element('legend', '', groupName));
            items.forEach(([key, label]) => {
                const wrapper = element('label', 'permission-toggle');
                const input = document.createElement('input');
                input.type = 'checkbox'; input.value = key; input.checked = selected.has(key);
                input.dataset.permissionKey = key;
                wrapper.append(input, element('span', '', label));
                group.append(wrapper);
            });
            permissionList.append(group);
        });
        permissionHint.hidden = !customized;
    }

    function setRoleOptions(user = null) {
        const ownerCount = currentOwnerCount();
        Array.from(roleSelect.options).forEach((option) => {
            option.disabled = !mayGrantRole(option.value);
        });
        if (user && user.role === 'owner' && actorRole === 'admin') {
            roleSelect.querySelector('option[value="owner"]').disabled = true;
        }
        if (user && user.role === 'owner' && ownerCount <= 1 && actorRole === 'owner') {
            roleSelect.querySelector('option[value="admin"]').disabled = false;
            roleSelect.querySelector('option[value="staff"]').disabled = false;
        }
    }

    function updatePermissionVisibility() {
        permissionFieldset.hidden = roleSelect.value !== 'staff';
    }

    function openDialog(dialog, opener) {
        state.returnFocus = opener || document.activeElement;
        dialog.showModal();
        dialog.querySelector('input:not([type="checkbox"]), select, button')?.focus();
    }

    function closeDialog(dialog) {
        if (dialog.open) dialog.close();
        state.returnFocus?.focus?.();
    }

    function resetAccessDialog() {
        state.selectedCandidate = null;
        state.selectedUser = null;
        delete accessDialog.dataset.userId;
        state.mode = 'create';
        candidateSearch.value = '';
        candidateResults.replaceChildren();
        candidateResults.hidden = true;
        selectedCandidateNode.replaceChildren();
        delete selectedCandidateNode.dataset.userId;
        selectedCandidateNode.hidden = true;
        document.getElementById('accessDialogTitle').textContent = 'Add staff';
        document.getElementById('accessConfirmationSummary').textContent = '';
        document.getElementById('accessPassword').value = '';
        dialogError.hidden = true;
        detailStep.hidden = false;
        passwordStep.hidden = true;
        document.getElementById('backToAccessDetails').hidden = true;
        saveButton.hidden = true;
        saveButton.textContent = 'Promote';
        continueButton.hidden = false;
        continueButton.disabled = true;
        roleSelect.value = 'staff';
        setRoleOptions();
        renderPermissions(config.staffPreset || []);
        candidatePicker.hidden = false;
        updatePermissionVisibility();
    }

    function openCreate(opener) {
        resetAccessDialog();
        openDialog(accessDialog, opener);
        candidateSearch.focus();
    }

    function openEdit(user, opener) {
        resetAccessDialog();
        state.mode = 'edit';
        state.selectedUser = user;
        accessDialog.dataset.userId = String(user.id);
        candidatePicker.hidden = true;
        document.getElementById('accessDialogTitle').textContent = `Edit ${user.name}`;
        continueButton.disabled = false;
        roleSelect.value = user.role;
        setRoleOptions(user);
        const permissions = user.role === 'staff' ? permissionNames(user.permissions) : [];
        renderPermissions(permissions, user.role === 'staff' && JSON.stringify(permissions) !== JSON.stringify(permissionNames(config.staffPreset)));
        updatePermissionVisibility();
        openDialog(accessDialog, opener);
        roleSelect.focus();
    }

    function openRemove(user, opener) {
        state.selectedUser = user;
        removeDialog.dataset.userId = String(user.id);
        document.getElementById('removeAccessDescription').textContent = `${user.name} will remain a customer and keep their account.`;
        document.getElementById('removeAccessPassword').value = '';
        openDialog(removeDialog, opener);
        document.getElementById('removeAccessPassword').focus();
    }

    function showCandidateResults(items) {
        state.candidateItems = items;
        state.candidateIndex = items.length ? 0 : -1;
        candidateResults.replaceChildren();
        candidateResults.hidden = items.length === 0;
        items.forEach((candidate, index) => {
            const option = element('button', 'candidate-option');
            option.type = 'button';
            option.setAttribute('role', 'option');
            option.id = `candidate-option-${index}`;
            option.dataset.userId = String(candidate.id);
            option.setAttribute('aria-selected', String(index === state.candidateIndex));
            option.append(element('strong', '', candidate.name || 'Unnamed account'));
            option.append(element('span', '', `@${candidate.username || '—'} · ${candidate.email || '—'}`));
            option.addEventListener('click', () => selectCandidate(candidate));
            candidateResults.append(option);
        });
        candidateSearch.setAttribute('aria-expanded', String(items.length > 0));
        candidateSearch.removeAttribute('aria-activedescendant');
        if (state.candidateIndex >= 0) candidateSearch.setAttribute('aria-activedescendant', `candidate-option-${state.candidateIndex}`);
    }

    function selectCandidate(candidate) {
        state.selectedCandidate = candidate;
        selectedCandidateNode.dataset.userId = String(candidate.id);
        candidateResults.hidden = true;
        candidateSearch.setAttribute('aria-expanded', 'false');
        candidateSearch.removeAttribute('aria-activedescendant');
        selectedCandidateNode.replaceChildren(
            element('strong', '', candidate.name || 'Unnamed account'),
            element('span', '', `@${candidate.username || '—'} · ${candidate.email || '—'}`),
        );
        selectedCandidateNode.hidden = false;
        continueButton.disabled = false;
    }

    async function searchCandidates() {
        clearTimeout(state.candidateTimer);
        state.candidateController?.abort();
        const term = candidateSearch.value.trim();
        if (term.length < 3) {
            candidateResults.replaceChildren(); candidateResults.hidden = true;
            candidateSearch.setAttribute('aria-expanded', 'false');
            candidateSearch.removeAttribute('aria-activedescendant');
            return;
        }
        state.candidateTimer = setTimeout(async () => {
            const controller = new AbortController();
            state.candidateController = controller;
            try {
                const params = new URLSearchParams({ q: term });
                const payload = await request(`${candidatesUrl}?${params}`, { signal: controller.signal });
                if (candidateSearch.value.trim() !== term) return;
                showCandidateResults(payload.candidates || []);
            } catch (candidateError) {
                if (candidateError.name === 'AbortError') return;
                setDialogError(candidateError.message);
            }
        }, 250);
    }

    function setDialogError(message) {
        dialogError.textContent = message;
        dialogError.hidden = false;
    }

    function enterPasswordStep() {
        if (state.mode === 'create' && !state.selectedCandidate) return;
        detailStep.hidden = true;
        passwordStep.hidden = false;
        continueButton.hidden = true;
        document.getElementById('backToAccessDetails').hidden = false;
        saveButton.hidden = false;
        saveButton.textContent = state.mode === 'create' ? 'Promote' : 'Save access';
        document.getElementById('accessConfirmationSummary').textContent = state.mode === 'create'
            ? `${state.selectedCandidate.name} will be promoted to ${roleNames[roleSelect.value]}.`
            : `Save access changes for ${state.selectedUser.name}?`;
        dialogError.hidden = true;
        passwordInput.focus();
    }

    async function saveAccess(event) {
        event.preventDefault();
        dialogError.hidden = true;
        const role = roleSelect.value;
        const payload = { role, password: passwordInput.value };
        if (role === 'staff') payload.permissions = currentPermissions();
        let url = createUrl;
        let method = 'POST';
        if (state.mode === 'create') {
            payload.user_id = state.selectedCandidate.id;
        } else {
            const id = encodeURIComponent(state.selectedUser.id);
            url = page.dataset.updateUrlTemplate.replace('__USER__', id);
            method = 'PATCH';
        }
        saveButton.disabled = true;
        try {
            const result = await request(url, { method, body: JSON.stringify(payload) });
            closeDialog(accessDialog);
            toast(result.message || 'User access saved.', 'success');
            await loadUsers({ refreshAll: true });
        } catch (saveError) {
            setDialogError(saveError.message);
        } finally {
            saveButton.disabled = false;
        }
    }

    async function removeAccess(event) {
        event.preventDefault();
        const button = document.getElementById('confirmRemoveAccess');
        button.disabled = true;
        try {
            const id = encodeURIComponent(state.selectedUser.id);
            const url = page.dataset.removeUrlTemplate.replace('__USER__', id);
            const result = await request(url, { method: 'POST', body: JSON.stringify({ password: document.getElementById('removeAccessPassword').value }) });
            closeDialog(removeDialog);
            toast(result.message || 'Elevated access removed.', 'success');
            await loadUsers({ refreshAll: true });
        } catch (removeError) {
            toast(removeError.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    document.getElementById('addStaffButton').addEventListener('click', (event) => openCreate(event.currentTarget));
    document.getElementById('retryUserAccess').addEventListener('click', () => loadUsers({ refreshAll: true }));
    document.getElementById('backToAccessDetails').addEventListener('click', () => {
        detailStep.hidden = false; passwordStep.hidden = true;
        document.getElementById('backToAccessDetails').hidden = true;
        saveButton.hidden = true; continueButton.hidden = false;
    });
    continueButton.addEventListener('click', enterPasswordStep);
    accessForm.addEventListener('submit', saveAccess);
    removeForm.addEventListener('submit', removeAccess);
    roleSelect.addEventListener('change', updatePermissionVisibility);
    search.addEventListener('input', () => {
        clearTimeout(search._filterTimer);
        search._filterTimer = setTimeout(() => loadUsers(), 220);
    });
    roleFilter.addEventListener('change', () => loadUsers());
    candidateSearch.addEventListener('input', searchCandidates);
    candidateSearch.addEventListener('keydown', (event) => {
        if (candidateResults.hidden || !state.candidateItems.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const delta = event.key === 'ArrowDown' ? 1 : -1;
            state.candidateIndex = (state.candidateIndex + delta + state.candidateItems.length) % state.candidateItems.length;
            Array.from(candidateResults.children).forEach((option, index) => option.setAttribute('aria-selected', String(index === state.candidateIndex)));
            candidateSearch.setAttribute('aria-activedescendant', `candidate-option-${state.candidateIndex}`);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            selectCandidate(state.candidateItems[state.candidateIndex]);
        }
    });
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(button.closest('dialog')));
    });
    [accessDialog, removeDialog].forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) closeDialog(dialog);
        });
        dialog.addEventListener('close', () => {
            state.returnFocus?.focus?.();
            state.returnFocus = null;
        });
    });

    updateOwnerMessage();
    loadUsers({ refreshAll: true });
})();
