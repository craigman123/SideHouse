@extends('layouts.user')

@section('title', 'Profile | Side House')
@section('page-title', 'My Profile')
@section('page-class', 'user-profile-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/profile.css') }}">
@endpush

@section('content')

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

                <div class="profile-identity">
                    <h2>{{ $user->name }}</h2>
                    <p class="profile-email-row">
                        {{ $user->email }}
                        @if ($user->email_verified_at)
                            <span class="verify-badge verify-badge-yes">Verified</span>
                        @else
                            <span class="verify-badge verify-badge-no">Unverified</span>
                        @endif
                    </p>

                    <p class="profile-email-row">
                        @if ($user->membership_verified_at)
                            <span class="verify-badge membership-badge-yes">Member</span>
                        @else
                            <span class="verify-badge membership-badge-no">Not a member</span>
                        @endif
                    </p>
                </div>

                <form method="POST" action="{{ role_route('logout') }}" class="logout-form" id="logoutForm">
                    @csrf
                    <button type="button" class="btn btn-danger btn-ghost-danger" id="logoutBtn">Log Out</button>
                </form>
            </div>

            <form method="POST" action="{{ route('user.profile.update') }}" id="profileForm">
                @csrf
                @method('PUT')

                <div class="profile-grid">
                    <div class="profile-item">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" readonly aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                        <span class="field-state">Read only. Select Edit Profile to change.</span>
                        @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="profile-item">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" readonly aria-invalid="{{ $errors->has('username') ? 'true' : 'false' }}">
                        <span class="field-state">Read only. Select Edit Profile to change.</span>
                        @error('username')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="profile-item">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" readonly aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                        <span class="field-state">Read only. Select Edit Profile to change.</span>
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
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
                            aria-invalid="{{ $errors->has('phone_number') ? 'true' : 'false' }}"
                        >
                        <span class="field-state">Read only. Select Edit Profile to change.</span>
                        @error('phone_number')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="profile-actions">
                    <button type="button" class="btn btn-secondary btn-ghost-danger" id="cancelEditBtn" hidden>Cancel</button>
                    <button type="button" class="btn btn-primary" id="editProfileBtn">Edit Profile</button>
                    <button type="submit" class="btn btn-primary" id="saveProfileBtn" hidden>Save Changes</button>
                </div>
            </form>

            <div class="profile-section">
                <h3 class="profile-section-title">Preferences</h3>

                <div class="preference-row">
                    <div>
                        <p class="preference-label">Dark Mode</p>
                        <p class="preference-sub">Choose the dark or light theme for your account.</p>
                    </div>
                    <button type="button" class="switch switch-disabled" role="switch" data-profile-theme-switch aria-checked="true" aria-label="Dark mode">
                        <span class="switch-slider"></span>
                    </button>
                </div>
            </div>

            <div class="profile-section customer-mfa-section">
                <h3 class="profile-section-title">Security</h3>
                @if ($user->hasMfaEnabled())
                    <div class="preference-row">
                        <div>
                            <p class="preference-label">Authenticator App</p>
                            <p class="preference-sub">Enabled with a TOTP authenticator app.</p>
                        </div>
                        <span class="mfa-state-badge">Enabled</span>
                    </div>
                    <form method="POST" action="{{ role_route('customer.profile.mfa.disable') }}" class="mfa-action-form">
                        @csrf
                        <label for="disableMfaCode">Current TOTP code or unused recovery code</label>
                        <input type="text" id="disableMfaCode" name="mfa_code" maxlength="10" autocomplete="one-time-code" required>
                        <button type="submit" class="btn btn-danger">Disable MFA</button>
                    </form>
                    <form method="POST" action="{{ role_route('customer.profile.mfa.recovery-codes') }}" class="mfa-action-form">
                        @csrf
                        <label for="regenerateMfaCode">Confirm with a current TOTP code</label>
                        <input type="text" id="regenerateMfaCode" name="mfa_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required>
                        <button type="submit" class="btn btn-secondary">Regenerate Recovery Codes</button>
                    </form>
                @else
                    <div class="preference-row">
                        <div>
                            <p class="preference-label">Authenticator App</p>
                            <p class="preference-sub">Protect sign-in with a TOTP authenticator app.</p>
                        </div>
                        <form method="POST" action="{{ role_route('customer.profile.mfa.setup') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">Enable MFA</button>
                        </form>
                    </div>
                @endif
                <div class="preference-row mfa-passkey-row">
                    <div>
                        <p class="preference-label">Biometrics / Passkey</p>
                        <p class="preference-sub">Coming soon.</p>
                    </div>
                    <button type="button" class="btn btn-secondary" disabled>Coming soon</button>
                </div>
                @if (session('customer_mfa_recovery_codes'))
                    <div class="mfa-recovery-panel">
                        <p><strong>Save these recovery codes once.</strong></p>
                        <ul>
                            @foreach (session('customer_mfa_recovery_codes') as $code)
                                <li><code>{{ $code }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

        </div>

        <div class="danger-zone">
            <h3 class="danger-zone-title">Danger Zone</h3>
            <div class="danger-zone-row">
                <div>
                    <p class="danger-zone-label">Delete Account</p>
                    <p class="danger-zone-sub">Permanently delete your account. This can't be undone.</p>
                </div>
                <button type="button" class="btn btn-danger btn-ghost-danger" id="deleteAccountBtn">Delete Account</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay wt-confirm-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" aria-describedby="logoutModalDescription">
        <div class="modal-box modal-box-sm wt-confirm-card">
            <div class="modal-header">
                <h3 id="logoutModalTitle">Log out?</h3>
                <button type="button" class="modal-close btn-ghost-danger" id="logoutModalClose" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text" id="logoutModalDescription">You'll need to sign in again to access your account.</p>

            <div class="modal-actions wt-confirm-actions">
                <button type="button" class="btn btn-secondary wt-confirm-stay btn-ghost-cancel" id="logoutModalCancel">Stay signed in</button>
                <button type="button" class="btn btn-danger wt-confirm-accept btn-ghost-danger" id="logoutConfirmSubmit">Log out</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay wt-confirm-overlay" id="deleteAccountModal" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle" aria-describedby="deleteModalDescription">
        <div class="modal-box modal-box-sm wt-confirm-card">
            <div class="modal-header">
                <h3 id="deleteModalTitle">Delete your account?</h3>
                <button type="button" class="modal-close btn-ghost-danger" id="deleteModalClose" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text" id="deleteModalDescription">This permanently deletes your account and cannot be undone.</p>
            <p class="modal-text">
                Type <strong>DELETE MY ACCOUNT</strong> below to confirm.
            </p>

            <form method="POST" action="{{ route('user.profile.destroy') }}" id="deleteAccountForm">
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
                <div class="modal-actions wt-confirm-actions">
                    <button type="button" class="btn btn-secondary wt-confirm-stay btn-ghost-cancel" id="deleteModalCancel">Keep my account</button>
                    <button type="submit" class="btn btn-danger wt-confirm-accept wt-confirm-accept-cancel" id="deleteConfirmSubmit" disabled>Yes, delete account</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

@endsection

@push('scripts')
    <script src="{{ asset('js/user/profile.js') }}" defer></script>
@endpush
