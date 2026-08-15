<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Register | Court Booking</title>
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
            <p class="subtitle">Create an account to manage bookings</p>

            <div
                id="googleSignInBtn"
                class="google-btn-wrap"
                data-google-client-id="{{ config('services.google.client_id') }}"
                data-google-auth-url="{{ route('auth.google') }}"
            ></div>

            <div class="auth-divider"><span>or</span></div>

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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('form');
            var submitBtn = document.getElementById('registerSubmitBtn');

            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Creating account…';
            });
        });
    </script>

    <script>
        // Google sign-in — same endpoint as the login page (auth.google
        // handles both login and first-time registration), so signing up
        // this way skips the form below entirely.
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
                        showToast(data.message || "Couldn't sign up with Google. Please try again.", 'error');
                        btnEl.classList.remove('google-btn-wrap-loading');
                        return;
                    }

                    showToast(data.message || 'Account created!', 'success');
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
                    text: 'signup_with',
                    shape: 'pill',
                    width: 300,
                });
            }

            if (window.google && window.google.accounts && window.google.accounts.id) {
                render();
            } else {
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