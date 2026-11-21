<section class="user-access-page" id="userAccessPage"
        data-list-url="{{ route('admin.users.index') }}"
        data-candidates-url="{{ route('admin.users.candidates') }}"
        data-create-url="{{ route('admin.users.store') }}"
        data-update-url-template="{{ route('admin.users.update', ['user' => '__USER__']) }}"
        data-remove-url-template="{{ route('admin.users.remove-access', ['user' => '__USER__']) }}"
        data-actor-role="{{ $actorRole }}"
        data-current-id="{{ $actorId }}"
        data-owner-exists="{{ $ownerExists ? 'true' : 'false' }}">
        <script type="application/json" id="userAccessConfig">@json(['permissions' => $permissionDefinitions, 'staffPreset' => $staffPreset])</script>

        <header class="user-access-heading">
            <div>
                <p class="user-access-eyebrow">People and permissions</p>
                <h2>Manage <span>user access</span></h2>
                <p>Review elevated accounts, assign staff permissions, and keep account changes auditable.</p>
            </div>
            <button class="user-access-primary" id="addStaffButton" type="button">Add staff</button>
        </header>

        <aside class="owner-bootstrap-banner" id="ownerBootstrapBanner" role="status" hidden>
            <strong>No owner account has been set.</strong>
            <span>Bootstrap the first owner from an existing account with <code>php artisan users:make-owner &lt;email&gt;</code>. Owner-only actions stay unavailable until an owner exists.</span>
        </aside>

        <section class="user-access-summary" id="userAccessSummary" aria-label="Elevated account counts" aria-live="polite">
            <span class="summary-skeleton"></span>
        </section>

        <section class="user-access-panel" aria-label="Elevated accounts">
            <div class="user-access-filters">
                <label class="user-access-search">
                    <span>Search people</span>
                    <input id="userAccessSearch" type="search" autocomplete="off" placeholder="Name, username, or full email">
                </label>
                <label class="user-access-role-filter">
                    <span>Role</span>
                    <select id="userAccessRoleFilter">
                        <option value="all">All roles</option>
                        <option value="owner">Owner</option>
                        <option value="admin">Admin</option>
                        <option value="staff">Staff</option>
                    </select>
                </label>
            </div>

            <div class="user-access-error" id="userAccessError" role="alert" hidden>
                <p>We could not load user access. Check your connection and try again.</p>
                <button type="button" id="retryUserAccess">Retry</button>
            </div>
            <div class="user-access-empty" id="userAccessEmpty" hidden>
                <strong>No elevated accounts match these filters.</strong>
                <span>Try a different search or role.</span>
            </div>
            <div class="user-access-loading" id="userAccessLoading" aria-label="Loading elevated accounts">
                <span></span><span></span><span></span>
            </div>

            <div class="user-access-table-wrap" id="userAccessTableWrap" hidden>
                <table class="user-access-table">
                    <thead><tr>
                        <th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th>
                        <th scope="col">MFA</th><th scope="col">Last login</th><th scope="col">Promoted by</th><th scope="col"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody id="userAccessTableBody"></tbody>
                </table>
            </div>
            <div class="user-access-cards" id="userAccessCards" aria-label="Elevated account cards" hidden></div>
        </section>
    </section>

    <dialog class="access-dialog" id="accessDialog" aria-labelledby="accessDialogTitle">
        <form class="access-dialog-card" id="accessForm" novalidate>
            <header class="access-dialog-header">
                <div><p class="user-access-eyebrow">Account permissions</p><h2 id="accessDialogTitle">Add staff</h2></div>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close dialog">×</button>
            </header>
            <div class="access-dialog-body" id="accessDetailsStep">
                <section id="candidatePicker">
                    <label class="field-label" for="candidateSearch">Find a registered customer</label>
                    <input id="candidateSearch" type="search" autocomplete="off" placeholder="Type at least 3 characters" role="combobox" aria-autocomplete="list" aria-controls="candidateResults" aria-expanded="false">
                    <div class="candidate-results" id="candidateResults" role="listbox" aria-label="Registered customer results" hidden></div>
                    <div class="selected-candidate" id="selectedCandidate" hidden></div>
                </section>

                <label class="field-label" for="accessRole">Role</label>
                <select id="accessRole" required>
                    <option value="staff">Staff</option><option value="admin">Admin</option><option value="owner">Owner</option>
                </select>

                <fieldset class="permission-fieldset" id="permissionFieldset">
                    <legend>Staff permissions</legend>
                    <p class="permission-customized-hint" id="permissionCustomizedHint" hidden>Customized from the staff preset.</p>
                    <div class="permission-toggle-list" id="permissionToggleList"></div>
                </fieldset>
            </div>
            <div class="access-confirm-step" id="passwordConfirmStep" hidden>
                <p id="accessConfirmationSummary"></p>
                <label class="field-label" for="accessPassword">Confirm your password</label>
                <input id="accessPassword" type="password" autocomplete="current-password" required>
                <p class="mfa-setup-note">They will be asked to set up MFA the next time they sign in.</p>
            </div>
            <p class="access-dialog-error" id="accessDialogError" role="alert" hidden></p>
            <footer class="access-dialog-footer">
                <button type="button" class="user-access-secondary" data-close-dialog>Cancel</button>
                <button type="button" class="user-access-secondary" id="backToAccessDetails" hidden>Back</button>
                <button type="button" class="user-access-primary" id="continueAccess" disabled>Continue</button>
                <button type="submit" class="user-access-primary" id="saveAccess" hidden>Promote</button>
            </footer>
        </form>
    </dialog>

    <dialog class="access-dialog" id="removeAccessDialog" aria-labelledby="removeDialogTitle">
        <form class="access-dialog-card" id="removeAccessForm" novalidate>
            <header class="access-dialog-header">
                <div><p class="user-access-eyebrow">Confirm change</p><h2 id="removeDialogTitle">Remove elevated access?</h2></div>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close dialog">×</button>
            </header>
            <div class="access-dialog-body">
                <p id="removeAccessDescription">This person will remain a customer and keep their account.</p>
                <label class="field-label" for="removeAccessPassword">Confirm your password</label>
                <input id="removeAccessPassword" type="password" autocomplete="current-password" required>
            </div>
            <footer class="access-dialog-footer">
                <button type="button" class="user-access-secondary" data-close-dialog>Cancel</button>
                <button type="submit" class="user-access-danger" id="confirmRemoveAccess">Remove access</button>
            </footer>
        </form>
    </dialog>
