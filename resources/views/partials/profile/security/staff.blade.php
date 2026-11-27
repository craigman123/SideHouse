@php
    $privilegedMfa = app(\App\Services\PrivilegedMfaService::class);
    $mfaAllowedMethods = $privilegedMfa->allowedMethods($user);
    $mfaState = $privilegedMfa->state($user);
    $totpAllowed = in_array('totp', $mfaAllowedMethods, true);
    $passkeyAllowed = in_array('passkey', $mfaAllowedMethods, true);
    $currentMethod = $user->mfa_method ?? 'totp';
    $biometricsSelected = $passkeyAllowed && (!$totpAllowed || $currentMethod === 'passkey');
    $passwordModalHasErrors = $errors->hasAny(['current_password', 'password', 'password_confirmation']);
@endphp

<div class="staff-profile-mfa-card">
    <div class="staff-security-header">
        <div>
            <p class="staff-mfa-description">
                @if (($mfaState['key'] ?? null) === 'disabled')
                    MFA is turned off by an administrator.
                @else
                    Choose how you confirm it's you.
                @endif
            </p>
        </div>
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
    </div>

    @if ($user->hasMfaEnabled() && ($user->mfa_method ?? 'totp') === 'totp')
        <div class="staff-recovery-section">
            <h3>Recovery codes</h3>
            <p>Use a code from your authenticator app to get new recovery codes.</p>
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
</div>

<section class="profile-section" aria-labelledby="staffPasswordRowTitle">
    <div class="preference-row">
        <div>
            <h3 class="profile-section-title" id="staffPasswordRowTitle">Change password</h3>
            <p class="preference-sub">Choose a new password for your staff account.</p>
        </div>
        <button type="button" class="btn btn-secondary" id="staffChangePasswordButton">Change password</button>
    </div>
</section>

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
