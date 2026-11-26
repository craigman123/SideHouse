@extends('staff.layouts.app')

@section('title', 'Staff Profile | Side House')
@section('page-title', 'Profile')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/profile.css') . '?v=' . filemtime(public_path('css/shared/profile.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/staff/staff-profile.css') . '?v=' . filemtime(public_path('css/staff/staff-profile.css')) }}">
@endpush

@php
    $privilegedMfa = app(\App\Services\PrivilegedMfaService::class);
    $mfaAllowedMethods = $privilegedMfa->allowedMethods($user);
    $mfaState = $privilegedMfa->state($user);
    $profileEditHasErrors = $errors->hasAny(['name', 'phone_number']);
    $passwordModalHasErrors = $errors->hasAny(['current_password', 'password', 'password_confirmation']);
    $totpAllowed = in_array('totp', $mfaAllowedMethods, true);
    $passkeyAllowed = in_array('passkey', $mfaAllowedMethods, true);
    $currentMethod = $user->mfa_method ?? 'totp';
    $biometricsSelected = $passkeyAllowed && (!$totpAllowed || $currentMethod === 'passkey');
@endphp

@section('content')
<div class="profile-page staff-profile-page" id="staffProfilePage">
    @if (session('success'))
        <p class="profile-flash-success" role="status">{{ session('success') }}</p>
    @endif
    @if ($errors->any() && !$passwordModalHasErrors)
        <p class="profile-flash-error" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="profile-card" aria-labelledby="staffProfileTitle">
        <header class="profile-header">
            <div class="profile-avatar-lg">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
            <div>
                <h2 id="staffProfileTitle">{{ $user->name }}</h2>
                <p>{{ $user->email }}</p>
                <span class="admin-role-badge">Staff</span>
            </div>
        </header>

        <form id="staffProfileForm" method="POST" action="{{ route('staff.profile.update') }}" data-edit-on-error="{{ $profileEditHasErrors ? 'true' : 'false' }}">
            @csrf
            @method('PUT')

            <div class="profile-grid">
                <div class="profile-item">
                    <label for="staffName">Name</label>
                    <input type="text" id="staffName" name="name" value="{{ old('name', $user->name) }}" autocomplete="name" maxlength="255" required readonly aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                    @error('name')
                        <span class="field-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>
                <div class="profile-item">
                    <label for="staffEmail">Email</label>
                    <input type="email" id="staffEmail" value="{{ $user->email }}" autocomplete="email" readonly>
                </div>
                <div class="profile-item">
                    <label for="staffPhone">Phone number</label>
                    <input type="tel" id="staffPhone" name="phone_number" value="{{ old('phone_number', $user->phone_number) }}" autocomplete="tel" readonly aria-invalid="{{ $errors->has('phone_number') ? 'true' : 'false' }}">
                    @error('phone_number')
                        <span class="field-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="profile-actions">
                <button type="button" class="btn btn-secondary" id="staffProfileEditButton" @if ($profileEditHasErrors) hidden @endif>Edit Profile</button>
                <button type="button" class="btn btn-quiet" id="staffProfileCancelButton" @unless ($profileEditHasErrors) hidden @endunless>Cancel</button>
                <button type="submit" class="btn btn-primary" id="staffProfileSaveButton" @unless ($profileEditHasErrors) hidden @endunless>Save Changes</button>
            </div>
        </form>
    </section>

    <div class="staff-password-trigger">
        <button type="button" class="btn btn-secondary" id="staffChangePasswordButton">Change password</button>
    </div>

    <section class="profile-card privileged-mfa-card staff-profile-mfa-card" aria-labelledby="privilegedMfaTitle">
        <header class="staff-mfa-header">
            <h2 id="privilegedMfaTitle">Security</h2>
            <p class="staff-mfa-description">
                @if (($mfaState['key'] ?? null) === 'disabled')
                    MFA is turned off by an administrator.
                @else
                    Choose how you confirm it's you.
                @endif
            </p>
        </header>

        <div class="staff-method-choice">
            <span class="staff-method-label">MFA</span>
            <button type="button" class="staff-method-toggle" id="staffMfaMethodSwitch" role="switch" aria-label="Choose between MFA and Biometrics" aria-checked="{{ $biometricsSelected ? 'true' : 'false' }}" aria-disabled="true" tabindex="0">
                <span class="staff-method-track" aria-hidden="true">
                    <span class="staff-method-thumb"></span>
                </span>
            </button>
            <div class="staff-method-biometrics" aria-label="Biometrics unavailable">
                <span class="staff-method-label">Biometrics</span>
                <span class="staff-method-coming-soon">Coming soon</span>
            </div>
        </div>

        @if ($user->hasMfaEnabled() && ($user->mfa_method ?? 'totp') === 'totp')
            <div class="staff-recovery-section">
                <h3>Recovery codes</h3>
                <p>Regenerate your codes using a code from your authenticator app.</p>
                @if (session('recovery_codes'))
                    <div class="recovery-codes recovery-codes-display" role="status">
                        <h3>Save your new recovery codes</h3>
                        <p>These are shown once. Store them somewhere safe.</p>
                        <ul>
                            @foreach (session('recovery_codes') as $recoveryCode)
                                <li><code>{{ $recoveryCode }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <form id="privilegedRecoveryCodesForm" method="POST" action="{{ role_route('admin.profile.mfa.recovery-codes') }}" class="staff-recovery-form">
                    @csrf
                    <div class="profile-item">
                        <label for="privilegedRecoveryCode">Authenticator code</label>
                        <input type="text" id="privilegedRecoveryCode" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}">
                        @error('code')
                            <span class="field-error" role="alert">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-actions">
                        <button type="submit" class="btn btn-primary">Regenerate recovery codes</button>
                    </div>
                </form>
            </div>
        @endif
    </section>

    <section class="profile-card staff-signout-card" aria-labelledby="staffSignoutTitle">
        <div>
            <h2 id="staffSignoutTitle">Sign out</h2>
            <p>End your staff session on this device.</p>
        </div>
        <form method="POST" action="{{ role_route('logout') }}" id="staffLogoutForm">
            @csrf
            <div class="profile-actions">
                <button class="btn btn-danger btn-ghost-danger" type="button" id="staffLogoutButton">Log out</button>
            </div>
        </form>
    </section>
</div>

<div class="staff-profile-modal-overlay{{ $passwordModalHasErrors ? ' open' : '' }}" id="staffPasswordModal" role="dialog" aria-modal="true" aria-labelledby="staffPasswordModalTitle" aria-describedby="staffPasswordModalDescription" aria-hidden="{{ $passwordModalHasErrors ? 'false' : 'true' }}" @unless ($passwordModalHasErrors) hidden @endunless>
    <section class="staff-profile-modal-card">
        <header class="staff-profile-modal-header">
            <h2 id="staffPasswordModalTitle">Change password</h2>
            <button type="button" class="staff-profile-modal-close" id="staffPasswordModalClose" aria-label="Close change password dialog">&times;</button>
        </header>
        <p id="staffPasswordModalDescription">Enter your current password and choose a new one.</p>
        <form id="staffPasswordForm" method="POST" action="{{ route('staff.profile.password') }}">
            @csrf
            @method('PUT')
            <div class="profile-item">
                <label for="staffCurrentPassword">Current password</label>
                <input type="password" id="staffCurrentPassword" name="current_password" autocomplete="current-password" required aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}">
                @error('current_password')
                    <span class="field-error" role="alert">{{ $message }}</span>
                @enderror
            </div>
            <div class="profile-item">
                <label for="staffNewPassword">New password</label>
                <input type="password" id="staffNewPassword" name="password" autocomplete="new-password" minlength="12" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                @error('password')
                    <span class="field-error" role="alert">{{ $message }}</span>
                @enderror
            </div>
            <div class="profile-item">
                <label for="staffConfirmPassword">Confirm new password</label>
                <input type="password" id="staffConfirmPassword" name="password_confirmation" autocomplete="new-password" minlength="12" required aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}">
                @error('password_confirmation')
                    <span class="field-error" role="alert">{{ $message }}</span>
                @enderror
            </div>
            <div class="staff-profile-modal-actions">
                <button type="button" class="btn btn-quiet" id="staffPasswordModalCancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Update password</button>
            </div>
        </form>
    </section>
</div>

<div class="modal-overlay" id="staffLogoutModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="staffLogoutModalTitle" aria-describedby="staffLogoutModalDescription" hidden>
    <div class="modal-box modal-box-sm">
        <div class="modal-header">
            <h3 id="staffLogoutModalTitle">Log out?</h3>
            <button type="button" class="modal-close btn-ghost-danger" id="staffLogoutModalClose" aria-label="Close">&times;</button>
        </div>
        <p class="modal-text" id="staffLogoutModalDescription">You'll need to sign in again to access the staff dashboard.</p>
        <div class="modal-actions">
            <button type="button" class="btn-secondary btn-ghost-cancel" id="staffLogoutModalCancel">Stay signed in</button>
            <button type="button" class="btn btn-danger btn-ghost-danger" id="staffLogoutConfirm">Log out</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/staff/staff-profile.js') }}?v={{ filemtime(public_path('js/staff/staff-profile.js')) }}" defer></script>
@endpush
