<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Two-Factor Authentication | Court Booking</title>
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

        <div class="mfa-modal-content" style="position: relative; z-index: 1;">
            <div class="mfa-modal-header">
                <h2>Two-Factor Authentication</h2>
            </div>

            <div class="mfa-modal-body">
                <p>Enter the 6-digit code from your authenticator app<br>(or a recovery code).</p>

                <form method="POST" action="{{ route('mfa.verify') }}">
                    @csrf
                    <div class=form-group>
                        <input type="text" name="code" maxlength="10" placeholder="6-digit code or recovery code" autocomplete="one-time-code" required autofocus>
                    </div>  
                    @error('code')
                        <div class="mfa-error">{{ $message }}</div>
                    @enderror

                    <button type="submit" class="mfa-btn">Verify</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>