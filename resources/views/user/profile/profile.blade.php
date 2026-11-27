@extends($profileLayout)

@section('title', $profileTitle ?? 'Profile | Side House')
@section('page-title', 'My Profile')
@section('page-class', 'user-profile-page')

@push('styles')
    @foreach ($profileStylesheets as $stylesheet)
        <link rel="stylesheet" href="{{ asset($stylesheet) }}?v={{ filemtime(public_path($stylesheet)) }}">
    @endforeach
@endpush

@section('content')
    @php
        $profileDomIds = array_replace([
            'form' => 'profileForm',
            'name' => 'name',
            'username' => 'username',
            'email' => 'email',
            'phone' => 'phone_number',
            'edit' => 'editProfileBtn',
            'cancel' => 'cancelEditBtn',
            'save' => 'saveProfileBtn',
            'logoutForm' => 'logoutForm',
            'logoutButton' => 'logoutBtn',
            'logoutModal' => 'logoutModal',
            'logoutTitle' => 'logoutModalTitle',
            'logoutClose' => 'logoutModalClose',
            'logoutDescription' => 'logoutModalDescription',
            'logoutCancel' => 'logoutModalCancel',
            'logoutConfirm' => 'logoutConfirmSubmit',
        ], $profileDomIds ?? []);
        $passwordModalHasErrors = $errors->hasAny(['current_password', 'password', 'password_confirmation']);
    @endphp
    <div class="user-profile-page" id="{{ $profilePageId ?? 'profilePage' }}">
    <div
        id="flash-data"
        data-success="{{ session('success') }}"
        data-error="{{ $errors->hasAny(['name', 'username', 'email', 'phone_number']) ? $errors->first() : '' }}"
        data-delete-error="{{ $showDelete ? $errors->first('confirmation') : '' }}"
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

                    @if ($showMembership)
                        <p class="profile-email-row">
                            @if ($user->membership_verified_at)
                                <span class="verify-badge membership-badge-yes">Member</span>
                            @else
                                <span class="verify-badge membership-badge-no">Not a member</span>
                            @endif
                        </p>
                    @endif
                </div>

                <form method="POST" action="{{ role_route('logout') }}" class="logout-form" id="{{ $profileDomIds['logoutForm'] }}">
                    @csrf
                    <button type="button" class="btn btn-danger btn-ghost-danger" id="{{ $profileDomIds['logoutButton'] }}">Log Out</button>
                </form>
            </div>

            <form method="POST" action="{{ $profileUpdateAction }}" id="{{ $profileDomIds['form'] }}" data-profile-form data-shared-profile>
                @csrf
                @method('PUT')

                <div class="profile-grid">
                    <div class="profile-item">
                        <label for="{{ $profileDomIds['name'] }}">Name</label>
                        <input type="text" id="{{ $profileDomIds['name'] }}" name="name" value="{{ old('name', $user->name) }}" data-profile-editable="true" readonly aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                        <span class="field-state">Read only. Select Edit Profile to change.</span>
                        @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    @if ($showUsername)
                        <div class="profile-item">
                            <label for="{{ $profileDomIds['username'] }}">Username</label>
                            <input type="text" id="{{ $profileDomIds['username'] }}" name="username" value="{{ old('username', $user->username) }}" data-profile-editable="{{ $canEditUsername ? 'true' : 'false' }}" readonly aria-invalid="{{ $errors->has('username') ? 'true' : 'false' }}">
                            <span class="field-state">Read only. Select Edit Profile to change.</span>
                            @error('username')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    @endif

                    <div class="profile-item">
                        <label for="{{ $profileDomIds['email'] }}">Email</label>
                        <input type="email" id="{{ $profileDomIds['email'] }}" @if ($canEditEmail) name="email" @endif value="{{ old('email', $user->email) }}" data-profile-editable="{{ $canEditEmail ? 'true' : 'false' }}" readonly aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                        <span class="field-state">{{ $canEditEmail ? 'Read only. Select Edit Profile to change.' : "Email can't be changed here." }}</span>
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="profile-item">
                        <label for="{{ $profileDomIds['phone'] }}">Phone Number</label>
                        <input
                            type="tel"
                            id="{{ $profileDomIds['phone'] }}"
                            name="phone_number"
                            value="{{ old('phone_number', $user->phone_number) }}"
                            data-profile-editable="true"
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
                    <button type="button" class="btn btn-secondary btn-ghost-danger" id="{{ $profileDomIds['cancel'] }}" data-profile-edit-cancel hidden>Cancel</button>
                    <button type="button" class="btn btn-primary" id="{{ $profileDomIds['edit'] }}" data-profile-edit>Edit Profile</button>
                    <button type="submit" class="btn btn-primary" id="{{ $profileDomIds['save'] }}" data-profile-save hidden>Save Changes</button>
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

            <div class="profile-section">
                <h3 class="profile-section-title">Security</h3>
                <div class="preference-row password-change-row">
                    <div>
                        <p class="preference-label">Change password</p>
                        <p class="preference-sub">Update the password used to sign in to your account.</p>
                        @unless ($canChangePassword)
                            <p class="field-state" id="profilePasswordDisabledReason">Password changes are unavailable while switched access is active.</p>
                        @endunless
                    </div>
                    <button type="button" class="btn btn-secondary" id="profilePasswordButton" aria-haspopup="dialog" aria-controls="profilePasswordModal" @unless ($canChangePassword) disabled aria-describedby="profilePasswordDisabledReason" @endunless>Change password</button>
                </div>
                @include($profileSecurityView)
            </div>

        </div>

        @if ($showDelete)
            <div class="danger-zone">
                <h3 class="danger-zone-title">Danger Zone</h3>
                <div class="danger-zone-row">
                    <div>
                        <p class="danger-zone-label">Delete Account</p>
                        <p class="danger-zone-sub">{{ $deleteZoneDescription }}</p>
                    </div>
                    <button type="button" class="btn btn-danger btn-ghost-danger" id="deleteAccountBtn">Delete Account</button>
                </div>
            </div>
        @endif
    </div>

    <div class="modal-overlay wt-confirm-overlay" id="{{ $profileDomIds['logoutModal'] }}" role="dialog" aria-modal="true" aria-labelledby="{{ $profileDomIds['logoutTitle'] }}" aria-describedby="{{ $profileDomIds['logoutDescription'] }}" @if ($logoutModalInitiallyHidden ?? false) aria-hidden="true" hidden @endif>
        <div class="modal-box modal-box-sm wt-confirm-card">
            <div class="modal-header">
                <h3 id="{{ $profileDomIds['logoutTitle'] }}">Log out?</h3>
                <button type="button" class="modal-close btn-ghost-danger" id="{{ $profileDomIds['logoutClose'] }}" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text" id="{{ $profileDomIds['logoutDescription'] }}">{{ $logoutDescription }}</p>

            <div class="modal-actions wt-confirm-actions">
                <button type="button" class="btn btn-secondary wt-confirm-stay btn-ghost-cancel" id="{{ $profileDomIds['logoutCancel'] }}">Stay signed in</button>
                <button type="button" class="btn btn-danger wt-confirm-accept btn-ghost-danger" id="{{ $profileDomIds['logoutConfirm'] }}">Log out</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay wt-confirm-overlay{{ $passwordModalHasErrors ? ' open' : '' }}" id="profilePasswordModal" role="dialog" aria-modal="true" aria-labelledby="profilePasswordModalTitle" aria-describedby="profilePasswordModalDescription" aria-hidden="{{ $passwordModalHasErrors ? 'false' : 'true' }}" @unless ($passwordModalHasErrors) hidden @endunless>
        <section class="wt-confirm-card profile-password-modal">
            <header class="modal-header">
                <h3 id="profilePasswordModalTitle">Change password</h3>
                <button type="button" class="modal-close btn-ghost-danger" id="profilePasswordModalClose" aria-label="Close change password dialog">&times;</button>
            </header>
            <p class="modal-text" id="profilePasswordModalDescription">Enter your current password and choose a new one.</p>
            <form method="POST" action="{{ $profilePasswordAction }}" id="profilePasswordForm">
                @csrf
                @method('PUT')
                <div class="profile-item">
                    <label for="profileCurrentPassword">Current password</label>
                    <div class="password-field-control">
                        <input type="password" id="profileCurrentPassword" name="current_password" autocomplete="current-password" required aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}">
                        <button type="button" class="password-visibility-toggle" data-password-visibility-toggle aria-controls="profileCurrentPassword" aria-label="Show current password" aria-pressed="false">Show</button>
                    </div>
                    @error('current_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="profile-item">
                    <label for="profileNewPassword">New password</label>
                    <div class="password-field-control">
                        <input type="password" id="profileNewPassword" name="password" autocomplete="new-password" minlength="12" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                        <button type="button" class="password-visibility-toggle" data-password-visibility-toggle aria-controls="profileNewPassword" aria-label="Show new password" aria-pressed="false">Show</button>
                    </div>
                    @error('password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="profile-item">
                    <label for="profilePasswordConfirmation">Confirm new password</label>
                    <div class="password-field-control">
                        <input type="password" id="profilePasswordConfirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}">
                        <button type="button" class="password-visibility-toggle" data-password-visibility-toggle aria-controls="profilePasswordConfirmation" aria-label="Show password confirmation" aria-pressed="false">Show</button>
                    </div>
                    @error('password_confirmation')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="profile-actions">
                    <button type="button" class="btn btn-secondary" id="profilePasswordCancel">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="profilePasswordSubmit">Update password</button>
                </div>
            </form>
        </section>
    </div>

    @if ($showDelete)
        <div class="modal-overlay wt-confirm-overlay" id="deleteAccountModal" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle" aria-describedby="deleteModalDescription">
            <div class="modal-box modal-box-sm wt-confirm-card">
                <div class="modal-header">
                    <h3 id="deleteModalTitle">{{ $deleteModalTitle }}</h3>
                    <button type="button" class="modal-close btn-ghost-danger" id="deleteModalClose" aria-label="Close">&times;</button>
                </div>

                <p class="modal-text" id="deleteModalDescription">{{ $deleteModalDescription }}</p>
                <p class="modal-text">Type <strong>DELETE MY ACCOUNT</strong> below to confirm.</p>

                <form method="POST" action="{{ $deleteAccountAction }}" id="deleteAccountForm">
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
                        <button type="button" class="btn btn-secondary wt-confirm-stay btn-ghost-cancel" id="deleteModalCancel">{{ $deleteCancelLabel }}</button>
                        <button type="submit" class="btn btn-danger wt-confirm-accept wt-confirm-accept-cancel" id="deleteConfirmSubmit" disabled>{{ $deleteSubmitLabel }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="toast-container" id="toastContainer"></div>
    </div>

@endsection

@push('scripts')
    <script src="{{ asset('js/user/profile.js') }}?v={{ filemtime(public_path('js/user/profile.js')) }}" defer></script>
    @foreach ($profileScripts as $script)
        <script src="{{ asset($script) }}?v={{ filemtime(public_path($script)) }}" defer></script>
    @endforeach
@endpush
