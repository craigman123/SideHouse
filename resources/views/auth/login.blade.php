<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login | Court Booking</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
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

        <div class="auth-box">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" style="width: 100%; max-width: 260px; display: block; margin: 0 auto 10px;">
            <p class="subtitle">Log in to manage customer bookings</p>

            <div
                id="googleSignInBtn"
                class="google-btn-wrap"
                data-google-client-id="{{ config('services.google.client_id') }}"
                data-google-auth-url="{{ route('auth.google') }}"
            ></div>

            <div class="auth-divider"><span>or</span></div>

            <form method="POST" action="{{ route('login.submit') }}">
                @csrf

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="{{ old('username') }}" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Show password">
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
                <br>Don't have an account? <a href="{{ route('register') }}">Register</a>
                <p class="auth-footer-back">Book as Guest <a href="{{ route('landing') }}">here</a></p>
                
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('form');
            var submitBtn = document.getElementById('loginSubmitBtn');

            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Logging in…';
            });
        });
    </script>

    <script>
        // Google sign-in — same Google Identity Services pattern already
        // used for guest checkout, just verified/logged-in server-side
        // instead of attached to a booking. One endpoint (auth.google)
        // handles both login and first-time registration.
        document.addEventListener('DOMContentLoaded', function () {
            var btnEl = document.getElementById('googleSignInBtn');
            if (!btnEl) return;

            var clientId = btnEl.dataset.googleClientId;
            var authUrl = btnEl.dataset.googleAuthUrl;
            if (!clientId || !authUrl) return;

            function getCookie(name) {
                var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                return match ? decodeURIComponent(match[1]) : null;
            }

            function csrfHeaders() {
                var metaToken = document.querySelector('meta[name="csrf-token"]');
                if (metaToken && metaToken.content) return { 'X-CSRF-TOKEN': metaToken.content };
                var cookieToken = getCookie('XSRF-TOKEN');
                if (cookieToken) return { 'X-XSRF-TOKEN': cookieToken };
                return {};
            }

            async function handleGoogleCredential(response) {
                btnEl.classList.add('google-btn-wrap-loading');

                try {
                    var res = await fetch(authUrl, {
                        method: 'POST',
                        headers: Object.assign(
                            { 'Content-Type': 'application/json', Accept: 'application/json' },
                            csrfHeaders()
                        ),
                        body: JSON.stringify({ id_token: response.credential }),
                    });

                    var data = await res.json().catch(function () { return {}; });

                    if (!res.ok) {
                        showToast(data.message || "Couldn't sign in with Google. Please try again.", 'error');
                        btnEl.classList.remove('google-btn-wrap-loading');
                        return;
                    }

                    showToast(data.message || 'Signed in!', 'success');
                    window.location.href = data.redirect || '{{ route("user.dashboard") }}';
                } catch (err) {
                    console.error(err);
                    showToast('Something went wrong. Please try again.', 'error');
                    btnEl.classList.remove('google-btn-wrap-loading');
                }
            }

            function render() {
                window.google.accounts.id.initialize({
                    client_id: clientId,
                    callback: handleGoogleCredential,
                    auto_select: false,
                });
                window.google.accounts.id.renderButton(btnEl, {
                    type: 'standard',
                    theme: 'filled_black',
                    size: 'large',
                    text: 'continue_with',
                    shape: 'pill',
                    width: 300,
                });
            }

            if (window.google && window.google.accounts && window.google.accounts.id) {
                render();
            } else {
                // The GIS script tag is `defer`, so it may not have
                // finished loading yet — poll briefly instead of
                // assuming load order.
                var attempts = 0;
                var wait = setInterval(function () {
                    attempts += 1;
                    if (window.google && window.google.accounts && window.google.accounts.id) {
                        clearInterval(wait);
                        render();
                    } else if (attempts > 40) {
                        clearInterval(wait);
                    }
                }, 250);
            }
        });
    </script>

    @if ($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast(@json($errors->first()), 'error');
            });
        </script>
    @endif

    @if (session('success'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast(@json(session('success')), 'success');
            });
        </script>
    @endif
</body>
</html>