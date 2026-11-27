@php
    $privilegedMfa = app(\App\Services\PrivilegedMfaService::class);
    $mfaState = $privilegedMfa->state($user);
@endphp

<div class="privileged-mfa-card">
    <div>
        <div class="profile-grid">
            <div class="profile-item">
                <span>Current method</span>
                <strong>{{ $user->hasMfaEnabled() ? 'Authenticator app (TOTP)' : 'Not enrolled' }}</strong>
            </div>
            <div class="profile-item">
                <span>Allowed methods</span>
                <strong>{{ $privilegedMfa->allowedMethodsLabel($user) }}</strong>
            </div>
            <div class="profile-item">
                <span>Status</span>
                <strong>{{ $mfaState['label'] }}</strong>
            </div>
        </div>
        <p>MFA is required for this account and cannot be turned off here.</p>
        <p>Passkey method switching is coming soon.</p>
    </div>

    @if ($user->hasMfaEnabled() && ($user->mfa_method ?? 'totp') === 'totp')
        <form method="POST" action="{{ role_route('admin.profile.mfa.recovery-codes') }}" id="privilegedRecoveryCodesForm">
            @csrf
            <div class="profile-grid">
                <div class="profile-item">
                    <label for="privilegedRecoveryCode">Current authenticator code</label>
                    <input id="privilegedRecoveryCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required>
                </div>
            </div>
            @error('code')<p class="profile-flash-error" role="alert">{{ $message }}</p>@enderror
            <div class="profile-actions">
                <button class="btn btn-primary" type="submit">Regenerate recovery codes</button>
            </div>
        </form>
    @endif

    @if (session('recovery_codes'))
        <div class="recovery-codes" role="status">
            <h3>Save your new recovery codes</h3>
            <p>These are shown once. Store them somewhere safe.</p>
            <ul>@foreach (session('recovery_codes') as $code)<li>{{ $code }}</li>@endforeach</ul>
        </div>
    @endif
</div>
