<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login | Court Booking</title>
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth/mfa-modal.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth/auth-pages.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    <script src="{{ asset('js/auth/mfa-modal.js') }}" defer></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="auth-page">
    @include('partials.guest.theme-toggle', ['placement' => 'corner'])
    <div class="auth-wrapper">
        <div class="auth-bubble-layer">
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
            <div class="bg-particle"></div>
        </div>

        <div class="auth-box">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" style="width: 100%; max-width: 260px; display: block; margin: 0 auto 10px;">
            <h1>Log in to your <span class="auth-heading-accent">account</span></h1>
            <p class="subtitle">{{ $loginLane === 'customer' ? 'Log in to manage customer bookings' : 'Log in to the '.ucfirst($loginLane).' dashboard' }}</p>

            <p class="google-caption">or continue with</p>
            <div class="google-signin-shell">
                <div class="google-slot" role="group" aria-label="Sign in with Google">
                    <div
                        id="googleSignInBtn"
                        data-google-client-id="{{ config('services.google.client_id') }}"
                        data-google-auth-url="{{ $googleAuthUrl }}"
                        data-redirect-fallback="{{ $loginHomeUrl }}"
                        data-button-text="continue_with"
                    ></div>
                </div>
                <span class="google-loading-status" aria-live="polite" aria-atomic="true"></span>
            </div>

            <form
                method="POST"
                action="{{ $loginSubmitUrl }}"
                id="login-form"
                data-mfa-setup-init-url="{{ $mfaSetupInitUrl }}"
                data-mfa-enable-url="{{ $mfaEnableUrl }}"
                data-mfa-verify-url="{{ $mfaVerifyUrl }}"
            >
                @csrf

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="{{ old('username') }}" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="toggle-password" aria-label="Show password">
                            <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary" id="loginSubmitBtn">Log In</button>
            </form>

            <div class="auth-footer">
                @if ($loginLane === 'customer')
                    <br>Don't have an account? <a href="{{ route('register') }}">Register</a>
                @endif
                <p class="auth-footer-back">Book as Guest <a href="{{ route('landing') }}">here</a></p>
            </div>
        </div>
    </div>

    {{-- ==================== MFA MODAL ==================== --}}
    <div id="mfa-modal" class="mfa-modal hidden">
        <div class="mfa-modal-overlay"></div>
        <div class="mfa-modal-content">
            <div class="mfa-modal-header">
                <h2 id="mfa-modal-title">Two-Factor Authentication</h2>
                <button type="button" id="mfa-modal-close" class="mfa-modal-close btn-ghost-danger" aria-label="Close">&times;</button>
            </div>

            <div class="mfa-modal-body">
                {{-- SETUP VIEW --}}
                <div id="mfa-setup-view" class="hidden">
                    <p>Scan this QR code with Google Authenticator or Authy:</p>
                    <div class="mfa-qr-wrapper">
                        <div id="mfa-qr-image"></div>
                    </div>
                    <p class="mfa-secret-text">Or enter this code manually:<br><code id="mfa-secret"></code></p>

                    <form id="mfa-setup-form">
                        <input type="text" id="mfa-setup-code" maxlength="6" placeholder="Enter 6-digit code" autocomplete="one-time-code" required>
                        <div id="mfa-setup-error" class="mfa-error hidden"></div>
                        <button type="submit" class="mfa-btn">Enable MFA</button>
                    </form>
                </div>

                {{-- CHALLENGE VIEW --}}
                <div id="mfa-challenge-view" class="hidden">
                    <p>Enter the 6-digit code from your authenticator app<br>(or a recovery code).</p>
                    <form id="mfa-challenge-form">
                        <input type="text" id="mfa-challenge-code" maxlength="10" placeholder="6-digit code or recovery code" autocomplete="one-time-code" required>
                        <div id="mfa-challenge-error" class="mfa-error hidden"></div>
                        <button type="submit" class="mfa-btn">Verify</button>
                    </form>
                </div>

                {{-- RECOVERY CODES VIEW --}}
                <div id="mfa-recovery-view" class="hidden">
                    <p><strong>Save these recovery codes</strong> in a safe place. Each can only be used once.</p>
                    <ul id="mfa-recovery-list" class="mfa-recovery-list"></ul>
                    <button id="mfa-recovery-continue" class="mfa-btn">Continue to Dashboard</button>
                    <button id="mfa-recovery-close" class="mfa-btn btn-ghost-danger">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/auth/google-signin.js') }}" defer></script>

    <div id="flashData"
        data-error="{{ $errors->any() ? $errors->first() : '' }}"
        data-success="{{ session('success') }}"
        style="display:none">
    </div>
    <script src="{{ asset('js/toast/flash-toast.js') }}" defer></script>
</body>
</html>
