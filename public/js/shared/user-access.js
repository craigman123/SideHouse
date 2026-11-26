(() => {
    const page = document.getElementById('userAccessPage');
    if (!page) return;

    const listUrl = page.dataset.listUrl;
    const candidatesUrl = page.dataset.candidatesUrl;
    const createUrl = page.dataset.createUrl;
    const actorRole = page.dataset.actorRole;
    const grantableRoles = (page.dataset.grantableRoles || '').split(',').filter(Boolean);
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
    const mfaDialog = document.getElementById('mfaActionDialog');
    const mfaForm = document.getElementById('mfaActionForm');
    const mfaCodeField = document.getElementById('mfaCodeField');
    const mfaCodeInput = document.getElementById('mfaActionCode');
    const mfaMethodsField = document.getElementById('mfaMethodsField');
    const mfaMethodsSelect = document.getElementById('mfaAllowedMethods');
    const mfaError = document.getElementById('mfaActionError');
    const mfaSubmit = document.getElementById('mfaActionSubmit');
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
    const candidateSearchStatus = document.getElementById('candidateSearchStatus');
    const candidateLoadingAnnouncement = document.getElementById('candidateLoadingAnnouncement');
    const selectedCandidateUserId = document.getElementById('selectedCandidateUserId');
    const selectedCandidateNode = document.getElementById('selectedCandidate');
    const configNode = document.getElementById('userAccessConfig');
    const detailsDialog = document.getElementById('accountDetailsDialog');
    const detailsForm = document.getElementById('accountDetailsForm');
    const detailsUrlTemplate = page.dataset.detailsUrlTemplate;
    const canEditDetails = page.dataset.canEditDetails === 'true' && Boolean(detailsDialog && detailsForm);
    const detailsInputs = canEditDetails ? {
        name: document.getElementById('accountDetailsName'),
        username: document.getElementById('accountDetailsUsername'),
        email: document.getElementById('accountDetailsEmail'),
        phone_number: document.getElementById('accountDetailsPhone'),
    } : {};

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
        candidateRequestVersion: 0,
        listController: null,
        returnFocus: null,
        detailsUser: null,
        detailsUpdatedAt: null,
        candidateLoad: null,
        detailsController: null,
        detailsRequestVersion: 0,
        mfaUser: null,
        mfaAction: null,
    };

    const roleNames = { owner: 'Owner', admin: 'Admin', staff: 'Staff', user: 'Customer' };
    const roleOrder = { owner: 0, admin: 1, staff: 2 };
    const permissionOrder = Object.keys(config.permissions || {});

    function setDetailsLoadState(loadState) {
        detailsForm.dataset.loadState = loadState;
        const loaded = loadState === 'loaded';
        const loading = loadState === 'loading';
        Object.entries(detailsInputs).forEach(([key, input]) => {
            input.disabled = !loaded;
            const skeleton = document.querySelector(`[data-details-skeleton="${key}"] .skeleton-input-bar`);
            if (skeleton) skeleton.hidden = !loading;
        });
        document.getElementById('continueAccountDetails').disabled = !loaded;
        if (loadState !== 'error') {
            document.getElementById('accountDetailsError').hidden = true;
            document.getElementById('retryAccountDetails').hidden = true;
        }
    }

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
            const error = new Error(validation || payload.message || `Request failed (${response.status}).`);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }
        return payload;
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '-';
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }

    function currentOwnerCount() {
        return state.allUsers.filter((user) => user.role === 'owner').length || (ownerAtRender ? 1 : 0);
    }

    function mayGrantRole(role) {
        return grantableRoles.includes(role);
    }

    function updateOwnerMessage() {
        ownerBanner.hidden = currentOwnerCount() > 0;
        const ownerOption = roleSelect.querySelector('option[value="owner"]');
        if (ownerOption) {
            ownerOption.disabled = !mayGrantRole('owner');
            ownerOption.title = ownerOption.disabled ? 'Only an admin can grant the owner role.' : '';
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
        return user.actions?.edit_access?.reason || 'You do not have permission to change this account.';
    }

    function openMfaDialog(user, action, opener) {
        state.mfaUser = user;
        state.mfaAction = action;
        mfaError.hidden = true;
        mfaCodeInput.value = '';
        mfaCodeInput.required = action === 'disable' || action === 'approve';
        mfaCodeField.hidden = !mfaCodeInput.required;
        mfaCodeInput.hidden = !mfaCodeInput.required;
        mfaMethodsField.hidden = action !== 'allowed_methods';
        mfaMethodsSelect.hidden = action !== 'allowed_methods';
        mfaMethodsSelect.value = user.mfa_pending_allowed_methods || user.mfa_allowed_methods || 'totp';

        const ownerRequest = action === 'owner_request_disable' || action === 'owner_request_enable';
        const actionNames = {
            disable: ['Disable MFA', 'Enter your current TOTP code to confirm this one-time action.'],
            enable: ['Enable MFA', 'The target will be required to enroll TOTP before accessing protected pages.'],
            force_reenroll: ['Force re-enrollment', 'The current factor stays active until the target verifies a new TOTP factor.'],
            allowed_methods: ['Allowed methods', 'Passkey-only access is unavailable until passkeys are implemented.'],
            owner_request_disable: ['Request owner MFA disable', 'A different real admin must approve this request with a fresh TOTP code within 24 hours.'],
            owner_request_enable: ['Request owner MFA re-enrollment', 'A different real admin must approve this request with a fresh TOTP code within 24 hours.'],
            approve: ['Approve owner MFA request', 'Enter your current TOTP code. It can approve only this one action.'],
        };
        const [title, description] = actionNames[action];
        document.getElementById('mfaActionTitle').textContent = title;
        document.getElementById('mfaActionDescription').textContent = ownerRequest
            ? `${description} Target: ${user.name}.`
            : `${description} Target: ${user.name}.`;
        mfaSubmit.textContent = action.startsWith('owner_request')
            ? 'Create request'
            : action === 'approve' ? 'Approve request' : 'Confirm';
        openDialog(mfaDialog, opener);
        if (mfaCodeInput.required) mfaCodeInput.focus();
        else if (action === 'allowed_methods') mfaMethodsSelect.focus();
        else mfaSubmit.focus();
    }

    function actionMenu(user) {
        const details = element('details', 'user-access-actions');
        details.dataset.userId = String(user.id);
        const summaryButton = element('summary', '', 'Actions');
        summaryButton.setAttribute('aria-label', `Actions for ${user.name}`);
        const menu = element('div', 'user-access-action-menu');
        const reason = guardReason(user);
        if (canEditDetails) {
            const detailsButton = element('button', '', 'Edit details');
            detailsButton.type = 'button';
            detailsButton.disabled = !user.actions?.edit_details?.allowed;
            detailsButton.title = user.actions?.edit_details?.reason || '';
            detailsButton.addEventListener('click', () => { details.open = false; openDetails(user, detailsButton); });
            menu.append(detailsButton);
        }
        const edit = element('button', '', 'Edit access');
        edit.type = 'button';
        edit.disabled = !user.actions?.edit_access?.allowed;
        edit.title = user.actions?.edit_access?.reason || '';
        edit.addEventListener('click', () => { details.open = false; openEdit(user, edit); });
        const remove = element('button', 'is-danger', 'Remove access');
        remove.type = 'button';
        remove.disabled = !user.actions?.remove_access?.allowed;
        remove.title = user.actions?.remove_access?.reason || '';
        remove.addEventListener('click', () => { details.open = false; openRemove(user, remove); });
        menu.append(edit, remove);

        const canManageMfa = page.dataset.canManageMfa === 'true'
            && user.mfa_actions
            && ['admin', 'owner', 'staff'].includes(user.role)
            && Number(user.id) !== Number(page.dataset.currentId);
        if (canManageMfa) {
            const addMfaAction = (label, action, danger = false) => {
                const button = element('button', danger ? 'is-danger' : '', label);
                button.type = 'button';
                button.addEventListener('click', () => {
                    details.open = false;
                    openMfaDialog(user, action, button);
                });
                menu.append(button);
            };

            if (user.mfa_actions.disable) addMfaAction('Disable MFA', 'disable', true);
            if (user.mfa_actions.enable) addMfaAction('Enable MFA', 'enable');
            if (user.mfa_actions.force_reenroll) addMfaAction('Force re-enrollment', 'force_reenroll');
            if (user.mfa_actions.allowed_methods) addMfaAction('Allowed methods', 'allowed_methods');
            if (user.mfa_actions.request_owner_change && user.mfa_state === 'enabled') {
                addMfaAction('Request disable MFA', 'owner_request_disable', true);
                addMfaAction('Request force re-enrollment', 'owner_request_enable');
            } else if (user.mfa_actions.request_owner_change && user.mfa_state === 'disabled') {
                addMfaAction('Request enable MFA', 'owner_request_enable');
            }
            if (user.mfa_actions.approve_owner_request) {
                addMfaAction('Approve MFA request', 'approve');
            }
        }

        details.append(summaryButton, menu);
        return details;
    }

    function personCell(user) {
        const cell = element('div', 'user-access-person');
        const nameRow = element('div', 'user-access-name-row');
        nameRow.append(element('strong', 'user-access-person-name', user.name || 'Unnamed account'));
        if (Number(user.id) === Number(window.userAccessCurrentId)) nameRow.append(element('span', 'you-badge', 'You'));
        cell.append(nameRow, element('span', 'user-access-username', `@${user.username || '-'}`));
        return cell;
    }

    function renderTableRow(user) {
        const row = document.createElement('tr');
        row.dataset.userId = String(user.id);
        const nameCell = document.createElement('td'); nameCell.append(personCell(user));
        const emailCell = element('td', 'user-access-email', user.email || '-');
        const roleCell = document.createElement('td'); roleCell.append(roleBadge(user.role));
        const mfaStatusClass = {
            enabled: 'is-enabled',
            disabled: 'is-disabled',
            reenrollment_required: 'is-reenrollment',
            pending_setup: 'is-pending',
        }[user.mfa_state] || 'is-pending';
        const mfaCell = document.createElement('td'); mfaCell.append(element('span', `mfa-status ${mfaStatusClass}`, user.mfa_state_label || 'Pending setup'));
        const lastLoginCell = element('td', 'user-access-date', formatDate(user.last_login_at));
        const promotedCell = document.createElement('td');
        const promoter = user.promoted_by?.name || '-';
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
            ['Email', user.email || '-'],
            ['MFA', user.mfa_state_label || 'Pending setup'],
            ['Last login', formatDate(user.last_login_at)],
            ['Promoted by', user.promoted_by?.name || '-'],
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
        Array.from(roleSelect.options).forEach((option) => {
            option.disabled = !mayGrantRole(option.value);
        });
        if (user && !grantableRoles.includes(user.role)) roleSelect.value = grantableRoles[0] || '';
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
        cancelCandidateSearch();
        state.selectedCandidate = null;
        state.selectedUser = null;
        delete accessDialog.dataset.userId;
        state.mode = 'create';
        candidateSearch.value = '';
        selectedCandidateUserId.value = '';
        candidateResults.replaceChildren();
        candidateResults.hidden = true;
        candidateSearch.setAttribute('aria-expanded', 'false');
        candidateSearch.removeAttribute('aria-activedescendant');
        setCandidateStatus('');
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

    async function openDetails(user, opener) {
        if (!canEditDetails) return;
        state.detailsController?.abort();
        state.detailsRequestVersion += 1;
        state.detailsUser = user;
        state.detailsUpdatedAt = null;
        detailsDialog.dataset.userId = String(user.id);
        document.getElementById('accountDetailsTitle').textContent = `Edit details for ${user.name}`;
        document.getElementById('accountDetailsError').hidden = true;
        document.getElementById('accountDetailsErrorMessage').textContent = '';
        document.getElementById('retryAccountDetails').hidden = true;
        const previousConflict = document.getElementById('accountDetailsConflict');
        if (previousConflict) { previousConflict.hidden = true; previousConflict.textContent = ''; }
        document.querySelectorAll('[data-details-error]').forEach((node) => { node.hidden = true; node.textContent = ''; });
        detailsForm.reset();
        Object.values(detailsInputs).forEach((input) => {
            input.value = '';
        });
        setDetailsLoadState('loading');
        document.getElementById('accountDetailsFieldsStep').hidden = false;
        document.getElementById('accountDetailsPasswordStep').hidden = true;
        document.getElementById('backToAccountDetails').hidden = true;
        document.getElementById('continueAccountDetails').hidden = false;
        document.getElementById('continueAccountDetails').disabled = true;
        document.getElementById('saveAccountDetails').hidden = true;
        if (!detailsDialog.open) openDialog(detailsDialog, opener);
        else state.returnFocus = opener || document.activeElement;
        await loadDetails(user.id);
    }

    async function loadDetails(userId) {
        state.detailsController?.abort();
        const controller = new AbortController();
        state.detailsController = controller;
        const requestVersion = ++state.detailsRequestVersion;
        const requestedUserId = Number(userId);
        state.detailsUpdatedAt = null;
        document.getElementById('accountDetailsErrorMessage').textContent = '';
        setDetailsLoadState('loading');
        try {
            const url = detailsUrlTemplate.replace('__USER__', encodeURIComponent(requestedUserId));
            const payload = await request(url, { signal: controller.signal });
            if (requestVersion !== state.detailsRequestVersion || !detailsDialog.open || Number(state.detailsUser?.id) !== requestedUserId) return;
            const requiredFields = ['name', 'username', 'email', 'updated_at'];
            if (!payload.details || !requiredFields.every((key) => typeof payload.details[key] === 'string' && payload.details[key].length > 0)
                || !Object.prototype.hasOwnProperty.call(payload.details, 'phone_number')) {
                throw new Error('The account details could not be loaded. Please retry.');
            }
            Object.entries(detailsInputs).forEach(([key, input]) => { input.value = payload.details?.[key] || ''; });
            state.detailsUpdatedAt = payload.details?.updated_at || null;
            setDetailsLoadState('loaded');
            detailsInputs.name.focus();
            detailsInputs.name.setSelectionRange(detailsInputs.name.value.length, detailsInputs.name.value.length);
        } catch (loadError) {
            if (loadError.name === 'AbortError' || requestVersion !== state.detailsRequestVersion) return;
            Object.values(detailsInputs).forEach((input) => {
                input.value = '';
            });
            setDetailsLoadState('error');
            showDetailsError(loadError.message || 'Could not load these account details. Please retry.', true);
        }
    }

    function showDetailsError(message, canRetry = false) {
        const node = document.getElementById('accountDetailsError');
        document.getElementById('accountDetailsErrorMessage').textContent = message;
        document.getElementById('retryAccountDetails').hidden = !canRetry;
        node.hidden = false;
    }

    function resetDetailsModal() {
        state.detailsController?.abort();
        state.detailsController = null;
        state.detailsRequestVersion += 1;
        state.detailsUser = null;
        state.detailsUpdatedAt = null;
        detailsForm.reset();
        document.querySelectorAll('[data-details-error]').forEach((node) => { node.hidden = true; node.textContent = ''; });
        const conflict = document.getElementById('accountDetailsConflict');
        if (conflict) { conflict.hidden = true; conflict.textContent = ''; }
        Object.values(detailsInputs).forEach((input) => {
            input.value = '';
        });
        setDetailsLoadState('idle');
        document.getElementById('accountDetailsErrorMessage').textContent = '';
    }

    function showDetailsValidation(errors = {}) {
        let first = null;
        Object.entries(errors).forEach(([field, messages]) => {
            const node = document.querySelector(`[data-details-error="${field}"]`);
            if (!node) return;
            node.textContent = Array.isArray(messages) ? messages[0] : String(messages);
            node.hidden = false;
            first ||= detailsInputs[field];
        });
        first?.focus();
    }

    function enterDetailsPasswordStep() {
        const invalid = Object.values(detailsInputs).find((input) => !input.checkValidity());
        if (invalid) { invalid.focus(); invalid.reportValidity(); return; }
        if (!state.detailsUpdatedAt) return;
        document.getElementById('accountDetailsFieldsStep').hidden = true;
        document.getElementById('accountDetailsPasswordStep').hidden = false;
        document.getElementById('continueAccountDetails').hidden = true;
        document.getElementById('backToAccountDetails').hidden = false;
        document.getElementById('saveAccountDetails').hidden = false;
        document.getElementById('accountDetailsPassword').focus();
    }

    async function saveDetails(event) {
        event.preventDefault();
        if (!state.detailsUpdatedAt || Object.values(detailsInputs).some((input) => input.disabled)) return;
        const errorNode = document.getElementById('accountDetailsError');
        errorNode.hidden = true;
        document.querySelectorAll('[data-details-error]').forEach((node) => { node.hidden = true; node.textContent = ''; });
        const button = document.getElementById('saveAccountDetails');
        button.disabled = true;
        const payload = Object.fromEntries(Object.entries(detailsInputs).map(([key, input]) => [key, input.value]));
        payload.expected_updated_at = state.detailsUpdatedAt;
        payload.password = document.getElementById('accountDetailsPassword').value;
        try {
            const url = detailsUrlTemplate.replace('__USER__', encodeURIComponent(state.detailsUser.id));
            const result = await request(url, { method: 'PATCH', body: JSON.stringify(payload) });
            closeDialog(detailsDialog);
            toast(result.message || 'Account details saved.', result.notice_sent === false ? 'error' : 'success');
            await loadUsers({ refreshAll: true });
        } catch (saveError) {
            showDetailsError(saveError.message);
            if (saveError.status === 422 && saveError.payload?.errors) {
                if (Object.keys(detailsInputs).some((field) => saveError.payload.errors[field])) {
                    document.getElementById('accountDetailsFieldsStep').hidden = false;
                    document.getElementById('accountDetailsPasswordStep').hidden = true;
                    document.getElementById('backToAccountDetails').hidden = true;
                    document.getElementById('saveAccountDetails').hidden = true;
                    document.getElementById('continueAccountDetails').hidden = false;
                }
                showDetailsValidation(saveError.payload.errors);
            }
            if (saveError.status === 409 && saveError.payload?.current) {
                const current = saveError.payload.current;
                Object.entries(detailsInputs).forEach(([key, input]) => { input.dataset.currentValue = current[key] || ''; });
                let conflict = document.getElementById('accountDetailsConflict');
                if (!conflict) {
                    conflict = element('p', 'account-details-conflict');
                    conflict.id = 'accountDetailsConflict';
                    errorNode.after(conflict);
                }
                conflict.textContent = `Current server values: ${current.name}, @${current.username}, ${current.email}, ${current.phone_number || 'no phone number'}. Review the form and save again.`;
                conflict.hidden = false;
            }
        } finally {
            button.disabled = false;
        }
    }

    function showCandidateResults(items) {
        state.candidateItems = items;
        state.candidateIndex = -1;
        candidateResults.replaceChildren();
        candidateResults.hidden = items.length === 0;
        items.forEach((candidate, index) => {
            const option = element('button', 'candidate-option');
            option.type = 'button';
            option.setAttribute('role', 'option');
            option.id = `candidate-option-${index}`;
            option.dataset.userId = String(candidate.id);
            option.setAttribute('aria-selected', 'false');
            option.append(element('strong', 'candidate-email', candidate.email || 'Email unavailable'));
            option.append(element('span', 'candidate-name', candidate.name || 'Unnamed customer'));
            option.addEventListener('click', () => selectCandidate(candidate));
            candidateResults.append(option);
        });
        candidateSearch.setAttribute('aria-expanded', String(items.length > 0));
        candidateSearch.removeAttribute('aria-activedescendant');
        setCandidateStatus(items.length ? '' : 'No registered customer with that email');
    }

    function selectCandidate(candidate) {
        cancelCandidateSearch();
        state.selectedCandidate = candidate;
        state.candidateItems = [];
        state.candidateIndex = -1;
        candidateSearch.value = candidate.email;
        selectedCandidateUserId.value = String(candidate.id);
        selectedCandidateNode.dataset.userId = String(candidate.id);
        closeCandidateResults();
        selectedCandidateNode.replaceChildren(
            element('span', 'selected-candidate-check', '✓'),
            element('strong', 'selected-candidate-email', candidate.email),
            element('span', 'selected-candidate-name', candidate.name || 'Unnamed customer'),
            element('span', 'selected-candidate-label', 'Selected'),
        );
        selectedCandidateNode.hidden = false;
        setCandidateStatus(`${candidate.email} selected.`);
        continueButton.disabled = false;
    }

    function closeCandidateResults() {
        candidateResults.hidden = true;
        candidateResults.setAttribute('aria-busy', 'false');
        candidateSearch.setAttribute('aria-expanded', 'false');
        candidateSearch.removeAttribute('aria-activedescendant');
    }

    function setCandidateStatus(message, isError = false) {
        candidateSearchStatus.textContent = message;
        candidateSearchStatus.classList.toggle('is-error', isError);
    }

    function cancelCandidateSearch() {
        clearTimeout(state.candidateTimer);
        if (state.candidateLoad) clearTimeout(state.candidateLoad.skeletonTimer);
        state.candidateLoad = null;
        state.candidateController?.abort();
        state.candidateController = null;
        state.candidateRequestVersion += 1;
        candidateResults.setAttribute('aria-busy', 'false');
        candidateLoadingAnnouncement.textContent = '';
        candidateSearchStatus.setAttribute('aria-live', 'polite');
    }

    function candidateSkeletonRows() {
        return Array.from({ length: 3 }, () => {
            const row = element('div', 'candidate-skeleton-row');
            row.setAttribute('aria-hidden', 'true');
            row.append(element('span', 'skeleton skeleton-line candidate-skeleton-email'));
            row.append(element('span', 'skeleton skeleton-line candidate-skeleton-name'));
            return row;
        });
    }

    function clearCandidateSelection() {
        state.selectedCandidate = null;
        selectedCandidateUserId.value = '';
        delete selectedCandidateNode.dataset.userId;
        selectedCandidateNode.replaceChildren();
        selectedCandidateNode.hidden = true;
        continueButton.disabled = state.mode === 'create';
    }

    function searchCandidates() {
        cancelCandidateSearch();
        clearCandidateSelection();
        state.candidateItems = [];
        state.candidateIndex = -1;
        candidateResults.replaceChildren();
        closeCandidateResults();
        setCandidateStatus('');
        const term = candidateSearch.value.trim();
        if (term.length < 3) return;

        const requestVersion = state.candidateRequestVersion;
        state.candidateTimer = setTimeout(async () => {
            const controller = new AbortController();
            state.candidateController = controller;
            const loadingState = { visible: false, shownAt: 0, skeletonTimer: null };
            state.candidateLoad = loadingState;
            candidateResults.setAttribute('aria-busy', 'true');
            candidateLoadingAnnouncement.textContent = 'Searching...';
            candidateSearchStatus.setAttribute('aria-live', 'off');
            setCandidateStatus('Searching...');
            loadingState.skeletonTimer = setTimeout(() => {
                if (requestVersion !== state.candidateRequestVersion) return;
                loadingState.visible = true;
                loadingState.shownAt = performance.now();
                candidateResults.replaceChildren(...candidateSkeletonRows());
                candidateResults.hidden = false;
                candidateSearch.setAttribute('aria-expanded', 'true');
            }, 150);
            try {
                const params = new URLSearchParams({ q: term });
                const payload = await request(`${candidatesUrl}?${params}`, { signal: controller.signal });
                if (requestVersion !== state.candidateRequestVersion || candidateSearch.value.trim() !== term) return;
                clearTimeout(loadingState.skeletonTimer);
                if (loadingState.visible) await new Promise((resolve) => setTimeout(resolve, Math.max(0, 300 - (performance.now() - loadingState.shownAt))));
                if (requestVersion !== state.candidateRequestVersion || candidateSearch.value.trim() !== term) return;
                state.candidateLoad = null;
                candidateResults.setAttribute('aria-busy', 'false');
                candidateLoadingAnnouncement.textContent = '';
                candidateSearchStatus.setAttribute('aria-live', 'polite');
                showCandidateResults(payload.candidates || []);
            } catch (candidateError) {
                if (candidateError.name === 'AbortError' || requestVersion !== state.candidateRequestVersion) return;
                clearTimeout(loadingState.skeletonTimer);
                if (loadingState.visible) await new Promise((resolve) => setTimeout(resolve, Math.max(0, 300 - (performance.now() - loadingState.shownAt))));
                if (requestVersion !== state.candidateRequestVersion) return;
                state.candidateLoad = null;
                candidateResults.replaceChildren();
                closeCandidateResults();
                candidateLoadingAnnouncement.textContent = '';
                candidateSearchStatus.setAttribute('aria-live', 'polite');
                setCandidateStatus('Could not search registered customers. Please try again.', true);
            }
        }, 250);
    }
    function setDialogError(message) {
        dialogError.textContent = message;
        dialogError.hidden = false;
    }

    function enterPasswordStep() {
        if (state.mode === 'create' && (!state.selectedCandidate || !selectedCandidateUserId.value)) return;
        detailStep.hidden = true;
        passwordStep.hidden = false;
        continueButton.hidden = true;
        document.getElementById('backToAccessDetails').hidden = false;
        saveButton.hidden = false;
        saveButton.textContent = state.mode === 'create' ? 'Promote' : 'Save access';
        document.getElementById('accessConfirmationSummary').textContent = state.mode === 'create'
            ? `${state.selectedCandidate.email} (${state.selectedCandidate.name}) will be promoted to ${roleNames[roleSelect.value]}.`
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
            payload.user_id = Number(selectedCandidateUserId.value);
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

    async function submitMfaAction(event) {
        event.preventDefault();
        mfaError.hidden = true;
        const user = state.mfaUser;
        const action = state.mfaAction;
        if (!user || !action) return;

        const userId = encodeURIComponent(user.id);
        const templates = {
            disable: page.dataset.mfaDisableUrlTemplate,
            enable: page.dataset.mfaEnableUrlTemplate,
            force_reenroll: page.dataset.mfaEnableUrlTemplate,
            allowed_methods: page.dataset.mfaAllowedMethodsUrlTemplate,
            owner_request_disable: page.dataset.mfaOwnerRequestUrlTemplate,
            owner_request_enable: page.dataset.mfaOwnerRequestUrlTemplate,
            approve: page.dataset.mfaOwnerApproveUrlTemplate,
        };
        let url = templates[action]?.replace('__USER__', userId) || '';
        let method = action === 'allowed_methods' ? 'PATCH' : 'POST';
        const payload = {};

        if (action === 'disable' || action === 'approve') payload.code = mfaCodeInput.value.trim();
        if (action === 'allowed_methods') payload.allowed_methods = mfaMethodsSelect.value;
        if (action === 'owner_request_disable') payload.action = 'disable';
        if (action === 'owner_request_enable') payload.action = 'enable';
        if (action === 'approve') {
            url = url.replace('__REQUEST__', encodeURIComponent(user.pending_mfa_request?.id || ''));
        }

        mfaSubmit.disabled = true;
        try {
            const result = await request(url, { method, body: JSON.stringify(payload) });
            closeDialog(mfaDialog);
            const emailStatus = result.email_notification?.status;
            const emailNotice = emailStatus === 'not_configured'
                ? ' Email notification is not configured.'
                : emailStatus === 'failed'
                    ? ' The action succeeded, but an email notification failed.'
                    : '';
            toast(
                (result.message || 'MFA settings updated.') + emailNotice,
                emailStatus === 'failed' ? 'error' : 'success',
            );
            await loadUsers({ refreshAll: true });
        } catch (actionError) {
            mfaError.textContent = actionError.message;
            mfaError.hidden = false;
        } finally {
            mfaSubmit.disabled = false;
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
    mfaForm.addEventListener('submit', submitMfaAction);
    if (canEditDetails) {
        document.getElementById('continueAccountDetails').addEventListener('click', enterDetailsPasswordStep);
        document.getElementById('backToAccountDetails').addEventListener('click', () => {
            document.getElementById('accountDetailsFieldsStep').hidden = false;
            document.getElementById('accountDetailsPasswordStep').hidden = true;
            document.getElementById('backToAccountDetails').hidden = true;
            document.getElementById('saveAccountDetails').hidden = true;
            document.getElementById('continueAccountDetails').hidden = false;
            detailsInputs.name.focus();
        });
        detailsForm.addEventListener('submit', saveDetails);
        document.getElementById('retryAccountDetails').addEventListener('click', () => {
            if (state.detailsUser) loadDetails(state.detailsUser.id);
        });
        document.querySelectorAll('[data-close-details]').forEach((button) => button.addEventListener('click', () => closeDialog(detailsDialog)));
        detailsDialog.addEventListener('click', (event) => { if (event.target === detailsDialog) closeDialog(detailsDialog); });
        detailsDialog.addEventListener('close', () => {
            state.returnFocus?.focus?.();
            state.returnFocus = null;
            resetDetailsModal();
        });
    }
    roleSelect.addEventListener('change', updatePermissionVisibility);
    search.addEventListener('input', () => {
        clearTimeout(search._filterTimer);
        search._filterTimer = setTimeout(() => loadUsers(), 220);
    });
    roleFilter.addEventListener('change', () => loadUsers());
    candidateSearch.addEventListener('input', searchCandidates);
    candidateSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !candidateResults.hidden) {
            event.preventDefault();
            cancelCandidateSearch();
            state.candidateItems = [];
            state.candidateIndex = -1;
            candidateResults.replaceChildren();
            closeCandidateResults();
            setCandidateStatus('');
            return;
        }
        if (candidateResults.hidden || !state.candidateItems.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (state.candidateIndex < 0) {
                state.candidateIndex = event.key === 'ArrowDown' ? 0 : state.candidateItems.length - 1;
            } else {
                const delta = event.key === 'ArrowDown' ? 1 : -1;
                state.candidateIndex = (state.candidateIndex + delta + state.candidateItems.length) % state.candidateItems.length;
            }
            Array.from(candidateResults.children).forEach((option, index) => option.setAttribute('aria-selected', String(index === state.candidateIndex)));
            candidateSearch.setAttribute('aria-activedescendant', `candidate-option-${state.candidateIndex}`);
            candidateResults.children[state.candidateIndex]?.scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter') {
            if (state.candidateIndex >= 0) {
                event.preventDefault();
                selectCandidate(state.candidateItems[state.candidateIndex]);
            }
        }
    });
    document.addEventListener('pointerdown', (event) => {
        if (candidateResults.hidden || candidatePicker.contains(event.target)) return;
        cancelCandidateSearch();
        state.candidateItems = [];
        state.candidateIndex = -1;
        candidateResults.replaceChildren();
        closeCandidateResults();
        setCandidateStatus('');
    });
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(button.closest('dialog')));
    });
    [accessDialog, removeDialog, mfaDialog].forEach((dialog) => {
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
