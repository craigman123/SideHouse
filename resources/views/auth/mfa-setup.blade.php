<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set up Two-Factor Authentication | Court Booking</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/mfa-modal.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
</head>
<body>
    <div class="auth-wrapper">
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>

        {{-- Re-uses the same visual language as the mfa-modal, but as a full page
             for users who land here directly (e.g. redirected by admin.mfa
             middleware) instead of through the login-page modal flow. --}}
        <div class="mfa-modal-content" style="position: relative; z-index: 1;">
            <div class="mfa-modal-header">
                <h2>Set up Two-Factor Authentication</h2>
            </div>

            <div class="mfa-modal-body">
                <p>Scan this QR code with Google Authenticator or Authy:</p>
                <div class="mfa-qr-wrapper">
                    <img
                        src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode($qrCodeUrl) }}"
                        alt="QR Code">
                </div>
                <p class="mfa-secret-text">Or enter this code manually:<br><code>{{ $secret }}</code></p>

                <form method="POST" action="{{ route('mfa.enable') }}">
                    @csrf
                    <div class="form-group">
                        <input type="text" name="code" maxlength="6" placeholder="Enter 6-digit code" autocomplete="one-time-code" required>
                    </div>
                    @error('code')
                        <div class="mfa-error">{{ $message }}</div>
                    @enderror

                    <button type="submit" class="mfa-btn">Enable MFA</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
