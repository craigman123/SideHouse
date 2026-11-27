<div class="customer-mfa-section">
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
