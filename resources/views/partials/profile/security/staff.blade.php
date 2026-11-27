@php
    $privilegedMfa = app(\App\Services\PrivilegedMfaService::class);
    $mfaAllowedMethods = $privilegedMfa->allowedMethods($user);
    $mfaState = $privilegedMfa->state($user);
    $totpAllowed = in_array('totp', $mfaAllowedMethods, true);
    $passkeyAllowed = in_array('passkey', $mfaAllowedMethods, true);
    $currentMethod = $user->mfa_method ?? 'totp';
    $biometricsSelected = $passkeyAllowed && (!$totpAllowed || $currentMethod === 'passkey');
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
