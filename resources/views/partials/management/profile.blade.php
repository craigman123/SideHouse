<div
        id="flash-data"
        data-success="{{ session('success') }}"
        data-error="{{ $errors->hasAny(['name', 'username', 'email', 'phone_number']) ? $errors->first() : '' }}"
        data-delete-error="{{ $errors->first('confirmation') }}"
    ></div>

    <div class="profile-page">
        <div class="profile-card">

            <div class="profile-header">
                <div class="profile-avatar-lg">
                    {{ strtoupper(substr($user->name, 0, 1)) }}
                </div>

                <div>
                    <h2>{{ $user->name }}</h2>
                    <p class="profile-email-row">
                        {{ $user->email }}
                        @if ($user->email_verified_at)
                            <span class="verify-badge verify-badge-yes">Verified</span>
                        @else
                            <span class="verify-badge verify-badge-no">Unverified</span>
                        @endif
                    </p>

                    <span class="admin-role-badge">Admin</span>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="logout-form" id="logoutForm">
                    @csrf
                    <button type="button" class="btn btn-danger" id="logoutBtn">Log Out</button>
                </form>
            </div>

            <form method="POST" action="{{ route(($ownerView ?? false) ? 'owner.profile.update' : 'admin.profile.update') }}" id="profileForm">
                @csrf
                @method('PUT')

                <div class="profile-grid">
                    <div class="profile-item">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" readonly>
                    </div>

                    <div class="profile-item">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" readonly>
                    </div>

                    <div class="profile-item">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" readonly>
                    </div>

                    <div class="profile-item">
                        <label for="phone_number">Phone Number</label>
                        <input
                            type="tel"
                            id="phone_number"
                            name="phone_number"
                            value="{{ old('phone_number', $user->phone_number) }}"
                            placeholder="09XX XXX XXXX"
                            autocomplete="tel"
                            readonly
                        >
                    </div>
                </div>

                <div class="profile-actions">
                    <button type="button" class="btn btn-secondary" id="cancelEditBtn" hidden>Cancel</button>
                    <button type="button" class="btn btn-primary" id="editProfileBtn">Edit Profile</button>
                    <button type="submit" class="btn btn-primary" id="saveProfileBtn" hidden>Save Changes</button>
                </div>
            </form>

        </div>

        <div class="danger-zone">
            <h3 class="danger-zone-title">Danger Zone</h3>
            <div class="danger-zone-row">
                <div>
                    <p class="danger-zone-label">Delete Account</p>
                    <p class="danger-zone-sub">Permanently delete your admin account. This can't be undone.</p>
                </div>
                <button type="button" class="btn btn-danger" id="deleteAccountBtn">Delete Account</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3>Log out?</h3>
                <button type="button" class="modal-close" id="logoutModalClose" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text">You'll need to sign in again to access the admin dashboard.</p>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="logoutModalCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="logoutConfirmSubmit">Log Out</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteAccountModal">
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3>Delete your account?</h3>
                <button type="button" class="modal-close" id="deleteModalClose" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text">
                This permanently deletes your admin account and can't be undone. You will
                <strong>lose access to the admin dashboard and all its data.</strong> If you're the
                only admin, make sure someone else has access first.
            </p>
            <p class="modal-text">
                Type <strong>DELETE MY ACCOUNT</strong> below to confirm.
            </p>

            <form method="POST" action="{{ route('admin.profile.destroy') }}" id="deleteAccountForm">
                @csrf
                @method('DELETE')
                <input
                    type="text"
                    name="confirmation"
                    id="deleteConfirmInput"
                    class="delete-confirm-input"
                    placeholder="DELETE MY ACCOUNT"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                >
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="deleteModalCancel">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="deleteConfirmSubmit" disabled>Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>
