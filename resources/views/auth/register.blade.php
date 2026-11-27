<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Register | Court Booking</title>
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth/auth-pages.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
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
            <h1>Create your <span class="auth-heading-accent">account</span></h1>
            <p class="subtitle">Create an account to manage bookings</p>

            <p class="google-caption">or sign up with</p>
            <div class="google-signin-shell">
                <div class="google-slot" role="group" aria-label="Sign in with Google">
                    <div
                        id="googleSignInBtn"
                        data-google-client-id="{{ config('services.google.client_id') }}"
                        data-google-auth-url="{{ route('auth.google') }}"
                        data-redirect-fallback="{{ route('user.dashboard') }}"
                        data-button-text="signup_with"
                    ></div>
                </div>
                <span class="google-loading-status" aria-live="polite" aria-atomic="true"></span>
            </div>

            <form method="POST" action="{{ route('register.submit') }}">
                @csrf

                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="{{ old('username') }}" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required>
                </div>

                <button type="submit" class="btn-primary" id="registerSubmitBtn">Register</button>
            </form>

            <div class="auth-footer">
                Already have an account? <a href="{{ route('login') }}">Log In</a>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/payment/register-form.js') }}" defer></script>
    <script src="{{ asset('js/auth/google-signin.js') }}" defer></script>

    <div id="flashData"
        data-error="{{ $errors->any() ? $errors->first() : '' }}"
        data-success="{{ session('success') }}"
        style="display:none">
    </div>
    <script src="{{ asset('js/toast/flash-toast.js') }}" defer></script>
</body>
</html>
