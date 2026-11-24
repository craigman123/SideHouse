@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/skeletons.css') . '?v=' . filemtime(public_path('css/shared/skeletons.css')) }}">
@endpush

<section class="user-access-page" id="userAccessPage"
        data-list-url="{{ route('admin.users.index') }}"
        data-candidates-url="{{ route('admin.users.candidates') }}"
        data-create-url="{{ route('admin.users.store') }}"
        data-update-url-template="{{ route('admin.users.update', ['user' => '__USER__']) }}"
        data-remove-url-template="{{ route('admin.users.remove-access', ['user' => '__USER__']) }}"
        data-details-url-template="{{ $actorRole === 'admin' ? route('admin.users.details', ['user' => '__USER__']) : '' }}"
        data-can-edit-details="{{ $actorRole === 'admin' ? 'true' : 'false' }}"
        data-actor-role="{{ $actorRole }}"
        data-current-id="{{ $actorId }}"
        data-owner-exists="{{ $ownerExists ? 'true' : 'false' }}"
        data-grantable-roles="{{ implode(',', $grantableRoles) }}">
        <script type="application/json" id="userAccessConfig">@json(['permissions' => $permissionDefinitions, 'staffPreset' => $staffPreset])</script>

        <header class="user-access-heading">
            <div>
                <p class="user-access-eyebrow">People and permissions</p>
                <h2>Manage <span>user access</span></h2>
                <p>Review elevated accounts, assign staff permissions, and keep account changes auditable.<br> <span style="color: gold">MFA</span> are mandatory for high user access, to prevent future compromission.</p>
            </div>
            <button class="user-access-primary" id="addStaffButton" type="button">Add staff</button>
        </header>

        <aside class="owner-bootstrap-banner" id="ownerBootstrapBanner" role="status" hidden>
            <strong>No owner account has been set.</strong>
            <span>Promote a registered customer to owner from this page to enable owner-only actions.</span>
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
                        @if ($actorRole === 'admin') <option value="user">Customer</option> @endif
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
                <section id="candidatePicker" class="candidate-picker">
                    <label class="field-label" for="candidateSearch">Customer email</label>
                    <div class="candidate-input-wrap">
                        <input id="candidateSearch" type="search" autocomplete="off" placeholder="Type at least 3 characters of the email" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="candidateResults" aria-describedby="candidateSearchStatus" aria-expanded="false">
                        <input id="selectedCandidateUserId" name="user_id" type="hidden" value="">
                    <div class="candidate-results" id="candidateResults" role="listbox" aria-label="Registered customer emails" aria-busy="false" hidden></div>
                    </div>
                    <p class="candidate-search-status" id="candidateSearchStatus" role="status" aria-live="polite"></p>
                    <span class="sr-only" id="candidateLoadingAnnouncement" aria-live="polite"></span>
                    <div class="selected-candidate" id="selectedCandidate" role="status" aria-live="polite" hidden></div>
                </section>

                <label class="field-label" for="accessRole">Role</label>
                <select id="accessRole" required>
                    @foreach ($grantableRoles as $role)
                        <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                    @endforeach
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
                <button type="button" class="user-access-secondary btn-ghost-cancel" data-close-dialog>Cancel</button>
                <button type="button" class="user-access-secondary btn-ghost-cancel" id="backToAccessDetails" hidden>Back</button>
                <button type="button" class="user-access-primary" id="continueAccess" disabled>Continue</button>
                <button type="submit" class="user-access-primary" id="saveAccess" hidden>Promote</button>
            </footer>
        </form>
    </dialog>

    @if ($actorRole === 'admin')
        <dialog class="access-dialog account-details-dialog" id="accountDetailsDialog" aria-labelledby="accountDetailsTitle">
            <form class="access-dialog-card" id="accountDetailsForm" data-load-state="idle" novalidate>
                <header class="access-dialog-header">
                    <div><p class="user-access-eyebrow">Account details</p><h2 id="accountDetailsTitle">Edit details</h2></div>
                    <button type="button" class="dialog-close" data-close-details aria-label="Close dialog">×</button>
                </header>
                <div class="account-details-error" id="accountDetailsError" role="alert" hidden>
                    <p id="accountDetailsErrorMessage"></p>
                    <button type="button" id="retryAccountDetails" class="user-access-secondary" hidden>Retry</button>
                </div>
                <div class="access-dialog-body" id="accountDetailsFieldsStep">
                    <div class="account-details-field">
                        <label class="field-label" for="accountDetailsName">Name</label>
                        <div class="skeleton-input" data-details-skeleton="name"><input id="accountDetailsName" name="name" type="text" maxlength="255" required autocomplete="name" aria-describedby="accountDetailsNameError" disabled><span class="skeleton skeleton-line skeleton-input-bar" aria-hidden="true" hidden></span></div>
                        <span class="account-details-field-error" id="accountDetailsNameError" data-details-error="name" hidden></span>
                    </div>
                    <div class="account-details-field">
                        <label class="field-label" for="accountDetailsUsername">Username</label>
                        <div class="skeleton-input" data-details-skeleton="username"><input id="accountDetailsUsername" name="username" type="text" maxlength="255" required autocomplete="username" aria-describedby="accountDetailsUsernameError" disabled><span class="skeleton skeleton-line skeleton-input-bar" aria-hidden="true" hidden></span></div>
                        <span class="account-details-field-error" id="accountDetailsUsernameError" data-details-error="username" hidden></span>
                    </div>
                    <div class="account-details-field">
                        <label class="field-label" for="accountDetailsEmail">Email</label>
                        <div class="skeleton-input" data-details-skeleton="email"><input id="accountDetailsEmail" name="email" type="email" maxlength="255" required autocomplete="email" aria-describedby="accountDetailsEmailNote accountDetailsEmailError" disabled><span class="skeleton skeleton-line skeleton-input-bar" aria-hidden="true" hidden></span></div>
                        <small class="account-details-email-note" id="accountDetailsEmailNote">Changing the email signs this person out and notifies the old address.</small>
                        <span class="account-details-field-error" id="accountDetailsEmailError" data-details-error="email" hidden></span>
                    </div>
                    <div class="account-details-field">
                        <label class="field-label" for="accountDetailsPhone">Phone number</label>
                        <div class="skeleton-input" data-details-skeleton="phone_number"><input id="accountDetailsPhone" name="phone_number" type="tel" maxlength="30" autocomplete="tel" pattern="[0-9+()\-\s]{7,30}" aria-describedby="accountDetailsPhoneError" disabled><span class="skeleton skeleton-line skeleton-input-bar" aria-hidden="true" hidden></span></div>
                        <span class="account-details-field-error" id="accountDetailsPhoneError" data-details-error="phone_number" hidden></span>
                    </div>
                </div>
                <div class="access-confirm-step" id="accountDetailsPasswordStep" hidden>
                    <p>Confirm your password to save these account details.</p>
                    <label class="field-label" for="accountDetailsPassword">Confirm your password</label>
                    <input id="accountDetailsPassword" name="password" type="password" autocomplete="current-password" required>
                </div>
                <footer class="access-dialog-footer">
                    <button type="button" class="user-access-secondary btn-ghost-cancel" data-close-details>Cancel</button>
                    <button type="button" class="user-access-secondary btn-ghost-cancel" id="backToAccountDetails" hidden>Back</button>
                    <button type="button" class="user-access-primary" id="continueAccountDetails" disabled>Continue</button>
                    <button type="submit" class="user-access-primary" id="saveAccountDetails" hidden>Save details</button>
                </footer>
            </form>
        </dialog>
    @endif

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
                <button type="button" class="user-access-secondary btn-ghost-cancel" data-close-dialog>Cancel</button>
                <button type="submit" class="user-access-danger" id="confirmRemoveAccess">Remove access</button>
            </footer>
        </form>
    </dialog>
