document.addEventListener('DOMContentLoaded', function () {
    var btnEl = document.getElementById('googleSignInBtn');
    if (!btnEl) return;
    var loadingShell = btnEl.parentElement;

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
    var authSubmitBtn = document.getElementById('loginSubmitBtn') || document.getElementById('registerSubmitBtn');
    var authForm = authSubmitBtn ? authSubmitBtn.form : null;
    var authFormInFlight = false;
    var googleInFlight = false;
    var googleLockedSubmit = false;
    var submitWasDisabled = false;
    var redirectFallbackTimer = null;

    function lockGoogleButton() {
        btnEl.classList.add('google-btn-wrap-locked');
        btnEl.setAttribute('aria-disabled', 'true');
    }

    function unlockGoogleButton() {
        if (googleInFlight || authFormInFlight) return;
        btnEl.classList.remove('google-btn-wrap-locked');
        btnEl.removeAttribute('aria-disabled');
    }

    function startGoogleLoading() {
        googleInFlight = true;
        submitWasDisabled = authSubmitBtn ? authSubmitBtn.disabled : false;
        googleLockedSubmit = Boolean(authSubmitBtn && !submitWasDisabled);
        if (googleLockedSubmit) authSubmitBtn.disabled = true;
        btnEl.classList.remove('google-btn-wrap-locked');
        btnEl.classList.add('google-btn-wrap-loading');
        loadingShell.classList.add('google-btn-wrap-loading');
        btnEl.setAttribute('aria-disabled', 'true');
        btnEl.setAttribute('aria-busy', 'true');
        var status = loadingShell.querySelector('.google-loading-status');
        var visual = loadingShell.querySelector('.google-loading-visual');
        if (status) status.textContent = 'Logging in...';
        if (visual) visual.hidden = false;
    }

    function resetGoogleLoading() {
        if (redirectFallbackTimer) {
            clearTimeout(redirectFallbackTimer);
            redirectFallbackTimer = null;
        }
        googleInFlight = false;
        btnEl.classList.remove('google-btn-wrap-loading');
        loadingShell.classList.remove('google-btn-wrap-loading');
        btnEl.removeAttribute('aria-busy');
        var status = loadingShell.querySelector('.google-loading-status');
        var visual = loadingShell.querySelector('.google-loading-visual');
        if (status) status.textContent = '';
        if (visual) visual.hidden = true;
        if (googleLockedSubmit && authSubmitBtn) authSubmitBtn.disabled = submitWasDisabled;
        googleLockedSubmit = false;
        unlockGoogleButton();
    }

    if (authForm) {
        authForm.addEventListener('submit', function (event) {
            if (googleInFlight) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            authFormInFlight = true;
            lockGoogleButton();
        }, true);
    }

    if (authSubmitBtn && window.MutationObserver) {
        var submitObserver = new MutationObserver(function () {
            if (!authSubmitBtn.disabled && authFormInFlight) {
                authFormInFlight = false;
                unlockGoogleButton();
            }
        });
        submitObserver.observe(authSubmitBtn, { attributes: true, attributeFilter: ['disabled'] });
    }

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        authFormInFlight = false;
        resetGoogleLoading();
        unlockGoogleButton();
    });

    // Only allow same-site paths ("/something", never "//host" or "/\host",
    // which browsers treat as protocol-relative → external redirect) —
    // same guard used in mfa-modal.js and book.js.
    function safeRedirectPath(path, fallback) {
        if (typeof path === 'string' && /^\/(?!\/|\\)/.test(path)) {
            return path;
        }
        return fallback;
    }

    async function handleGoogleCredential(response) {
        if (googleInFlight || authFormInFlight) return;
        startGoogleLoading();

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
                resetGoogleLoading();
                return;
            }

            if (data.mfa_required) {
                var loginForm = document.getElementById('login-form');
                if (loginForm && data.mfa_urls) {
                    if (typeof data.mfa_urls.setup_init === 'string') loginForm.dataset.mfaSetupInitUrl = data.mfa_urls.setup_init;
                    if (typeof data.mfa_urls.enable === 'string') loginForm.dataset.mfaEnableUrl = data.mfa_urls.enable;
                    if (typeof data.mfa_urls.verify === 'string') loginForm.dataset.mfaVerifyUrl = data.mfa_urls.verify;
                }
                window.dispatchEvent(new CustomEvent('customer-mfa-required', {
                    detail: { type: data.mfa_type || 'challenge' }
                }));
                resetGoogleLoading();
                return;
            }

            showToast(data.message || (btnEl.dataset.buttonText === 'signup_with' ? 'Account created!' : 'Signed in!'), 'success');
            window.location.href = safeRedirectPath(data.redirect, redirectFallback);
            redirectFallbackTimer = setTimeout(resetGoogleLoading, 10000);
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
            resetGoogleLoading();
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
