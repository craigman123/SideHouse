document.addEventListener('DOMContentLoaded', () => {
    const loginForm   = document.getElementById('login-form');
    const submitBtn   = document.getElementById('loginSubmitBtn');
    const modal       = document.getElementById('mfa-modal');
    const setupView   = document.getElementById('mfa-setup-view');
    const challengeView = document.getElementById('mfa-challenge-view');
    const recoveryView  = document.getElementById('mfa-recovery-view');

    // Was previously undefined here — only existed inside google-signin.js's
    // own closure. Read it off the same data attribute on the login page.
    const redirectFallback = document.getElementById('googleSignInBtn')?.dataset.redirectFallback || '/';

    // ---------- Close Modal ----------
    document.getElementById('mfa-modal-close').onclick = () => {
        modal.classList.add('hidden');
    };

    if (!loginForm) return;

    // ---------- Login form → AJAX ----------
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        submitBtn.disabled = true;
        submitBtn.textContent = 'Logging in…';

        const formData = new FormData(loginForm);

        try {
            const res = await fetch(loginForm.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });

            const data = await res.json();
            updateCsrfToken(data.csrf_token);

            if (!res.ok) {
                showToast(data.message || 'Invalid username or password.', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Log In';
                return;
            }

            if (data.mfa_required) {
                if (data.mfa_type === 'setup') {
                    await openSetupModal();
                } else {
                    openChallengeModal();
                }
                submitBtn.disabled = false;
                submitBtn.textContent = 'Log In';
                return;
            }

            // Normal user
            submitBtn.textContent = 'Redirecting…';
            window.location.href = safeRedirectPath(data.redirect, redirectFallback);
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Log In';
        }
    });

    function safeRedirectPath(path, fallback) {
        if (typeof path === 'string' && /^\/(?!\/|\\)/.test(path)) {
            return path;
        }
        return fallback;
    }

    // ---------- Setup Modal ----------
    async function openSetupModal() {
        const res = await fetch('/mfa/setup/init', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        });

        const data = await res.json();

        const wrapper = document.getElementById('mfa-qr-image');
        wrapper.innerHTML = data.qrCodeSvg;
        document.getElementById('mfa-secret').textContent = data.secret;

        document.getElementById('mfa-modal-title').textContent = 'Set up Two-Factor Authentication';
        setupView.classList.remove('hidden');
        challengeView.classList.add('hidden');
        recoveryView.classList.add('hidden');
        modal.classList.remove('hidden');
    }

    document.getElementById('mfa-setup-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const code = document.getElementById('mfa-setup-code').value.trim();
        const errorEl = document.getElementById('mfa-setup-error');
        errorEl.classList.add('hidden');

        const setupSubmitBtn = e.target.querySelector('button[type="submit"]');
        const originalLabel = setupSubmitBtn.textContent;
        setupSubmitBtn.disabled = true;
        setupSubmitBtn.textContent = 'Enabling MFA…';

        try {
            const res = await fetch('/mfa/enable', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ code }),
            });

            const data = await res.json();
            updateCsrfToken(data.csrf_token);

            if (!res.ok) {
                errorEl.textContent = data.message || 'Invalid code';
                errorEl.classList.remove('hidden');
                setupSubmitBtn.disabled = false;
                setupSubmitBtn.textContent = originalLabel;
                return;
            }

            // Show recovery codes
            const list = document.getElementById('mfa-recovery-list');
            list.innerHTML = '';
            data.recovery_codes.forEach(code => {
                const li = document.createElement('li');
                li.textContent = code;
                list.appendChild(li);
            });

            setupView.classList.add('hidden');
            recoveryView.classList.remove('hidden');
            document.getElementById('mfa-modal-title').textContent = 'Save your Recovery Codes';

            const continueBtn = document.getElementById('mfa-recovery-continue');
            continueBtn.onclick = () => {
                continueBtn.disabled = true;
                continueBtn.textContent = 'Redirecting to dashboard…';
                window.location.href = safeRedirectPath(data.redirect, redirectFallback);
            };
        } catch (err) {
            console.error(err);
            errorEl.textContent = 'Something went wrong. Please try again.';
            errorEl.classList.remove('hidden');
            setupSubmitBtn.disabled = false;
            setupSubmitBtn.textContent = originalLabel;
        }
    });

    // ---------- Challenge Modal ----------
    function openChallengeModal() {
        document.getElementById('mfa-modal-title').textContent = 'Two-Factor Authentication';
        setupView.classList.add('hidden');
        recoveryView.classList.add('hidden');
        challengeView.classList.remove('hidden');
        modal.classList.remove('hidden');
        document.getElementById('mfa-challenge-code').focus();
    }

    function updateCsrfToken(token) {
        if (!token) return;
        document.querySelector('meta[name="csrf-token"]').setAttribute('content', token);
    }

    document.getElementById('mfa-challenge-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const code = document.getElementById('mfa-challenge-code').value.trim();
        const errorEl = document.getElementById('mfa-challenge-error');
        errorEl.classList.add('hidden');

        const verifyBtn = e.target.querySelector('button[type="submit"]');
        const originalLabel = verifyBtn.textContent;
        verifyBtn.disabled = true;
        verifyBtn.textContent = 'Verifying…';

        try {
            const res = await fetch('/mfa/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ code }),
            });

            const data = await res.json();
            updateCsrfToken(data.csrf_token);

            if (!res.ok) {
                errorEl.textContent = data.message || 'Invalid code';
                errorEl.classList.remove('hidden');
                verifyBtn.disabled = false;
                verifyBtn.textContent = originalLabel;
                return;
            }

            verifyBtn.textContent = 'Redirecting to dashboard…';
            window.location.href = safeRedirectPath(data.redirect, redirectFallback);
        } catch (err) {
            console.error(err);
            errorEl.textContent = 'Something went wrong. Please try again.';
            errorEl.classList.remove('hidden');
            verifyBtn.disabled = false;
            verifyBtn.textContent = originalLabel;
        }
    });
});