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

    var redirectFallback = btnEl.dataset.redirectFallback || '/';

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
                showToast(data.message || (btnEl.dataset.buttonText === 'signup_with'
                    ? "Couldn't sign up with Google. Please try again."
                    : "Couldn't sign in with Google. Please try again."), 'error');
                btnEl.classList.remove('google-btn-wrap-loading');
                return;
            }

            showToast(data.message || (btnEl.dataset.buttonText === 'signup_with' ? 'Account created!' : 'Signed in!'), 'success');
            window.location.href = data.redirect || redirectFallback;
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
            text: btnEl.dataset.buttonText || 'continue_with',
            shape: 'pill',
            width: 270,
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