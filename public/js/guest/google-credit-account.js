(function (global) {
    const identityKey = 'sidehouse_guest_google_identity';
    const googleScript = document.getElementById('googleIdentityServicesScript');
    let googleScriptFailure = null;

    if (googleScript) {
        googleScript.addEventListener('error', () => {
            googleScriptFailure = makeError('Google sign-in could not load.', 'google-script-unavailable');
        }, { once: true });
    }

    function makeError(message, code) {
        const error = new Error(message);
        error.code = code;
        return error;
    }

    function validEmail(value) {
        return typeof value === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
    }

    function pictureUrl(value) {
        if (typeof value !== 'string' || !value.trim()) return '';
        try {
            const url = new URL(value);
            return url.protocol === 'https:' && url.hostname === 'lh3.googleusercontent.com' ? url.href : '';
        } catch (_) {
            return '';
        }
    }

    function readIdentity() {
        try {
            const raw = localStorage.getItem(identityKey);
            if (!raw) return null;

            let saved;
            try {
                saved = JSON.parse(raw);
            } catch (_) {
                return validEmail(raw) ? { email: raw.trim(), name: '', picture: '' } : null;
            }

            if (typeof saved === 'string' && validEmail(saved)) {
                return { email: saved.trim(), name: '', picture: '' };
            }
            if (!saved || typeof saved !== 'object' || Array.isArray(saved) || !validEmail(saved.email)) {
                return null;
            }

            return {
                email: saved.email.trim(),
                name: typeof saved.name === 'string' ? saved.name.trim() : '',
                picture: pictureUrl(saved.picture),
            };
        } catch (_) {
            return null;
        }
    }

    function saveIdentity(identity) {
        if (!identity || !validEmail(identity.email)) return false;
        try {
            localStorage.setItem(identityKey, JSON.stringify({
                email: identity.email.trim(),
                name: typeof identity.name === 'string' ? identity.name.trim() : '',
                picture: pictureUrl(identity.picture),
            }));
            return true;
        } catch (_) {
            return false;
        }
    }

    function clearIdentity() {
        try {
            localStorage.removeItem(identityKey);
        } catch (_) {}
    }

    function decodeCredential(credential) {
        try {
            const encoded = credential.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
            const padded = encoded.padEnd(Math.ceil(encoded.length / 4) * 4, '=');
            const json = decodeURIComponent(atob(padded).split('').map((character) => {
                return '%' + character.charCodeAt(0).toString(16).padStart(2, '0');
            }).join(''));
            return JSON.parse(json);
        } catch (_) {
            return {};
        }
    }

    function waitForGoogleIdentityServices(timeoutMs) {
        if (global.google?.accounts?.id) return Promise.resolve(global.google.accounts.id);
        if (googleScriptFailure) return Promise.reject(googleScriptFailure);
        if (!googleScript) {
            return Promise.reject(makeError('Google sign-in is unavailable on this page.', 'google-script-missing'));
        }

        return new Promise((resolve, reject) => {
            let settled = false;
            const timer = setTimeout(() => {
                finish(reject, makeError(
                    'Google sign-in could not load. Check your connection or content blocker and try again.',
                    'google-script-unavailable'
                ));
            }, timeoutMs || 10000);

            function finish(callback, value) {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                googleScript.removeEventListener('load', onLoad);
                googleScript.removeEventListener('error', onError);
                callback(value);
            }

            function onLoad() {
                if (global.google?.accounts?.id) finish(resolve, global.google.accounts.id);
                else finish(reject, makeError('Google sign-in did not initialize correctly.', 'google-script-unavailable'));
            }

            function onError() {
                finish(reject, googleScriptFailure || makeError(
                    'Google sign-in could not load. Check your connection or content blocker and try again.',
                    'google-script-unavailable'
                ));
            }

            googleScript.addEventListener('load', onLoad, { once: true });
            googleScript.addEventListener('error', onError, { once: true });

            if (global.google?.accounts?.id) finish(resolve, global.google.accounts.id);
        });
    }

    async function requestNonce(url, csrfToken) {
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
            });
        } catch (_) {
            throw makeError('Could not reach the server to prepare Google sign-in. Check your connection and try again.', 'nonce-network');
        }

        let data = {};
        try {
            data = await response.json();
        } catch (_) {}

        if (!response.ok || typeof data.nonce !== 'string' || !data.nonce) {
            throw makeError(data.message || 'Could not prepare Google sign-in. Please try again.', 'nonce-failed');
        }
        return data.nonce;
    }

    function renderVerifiedButton(options) {
        const {
            container,
            clientId,
            nonceUrl,
            verifyUrl,
            csrfToken,
            isActive,
            onVerifying,
            onReady,
            onVerified,
            onUnmatched,
            onError,
        } = options;

        if (!container) {
            onError?.(makeError('Google sign-in button is unavailable.', 'button-missing'));
            return Promise.resolve();
        }
        if (!clientId) {
            onError?.(makeError('Google sign-in is not configured on this page.', 'client-id-missing'));
            return Promise.resolve();
        }

        container.replaceChildren();

        return Promise.all([
            waitForGoogleIdentityServices(),
            requestNonce(nonceUrl, csrfToken),
        ]).then(([googleIdentity, nonce]) => {
            if (isActive && !isActive()) return;

            googleIdentity.disableAutoSelect();
            googleIdentity.initialize({
                client_id: clientId,
                auto_select: false,
                nonce,
                callback: async (response) => {
                    if (isActive && !isActive()) return;
                    if (!response || typeof response.credential !== 'string') {
                        onError?.(makeError('Google did not return a sign-in credential. Please try again.', 'credential-missing'));
                        return;
                    }

                    onVerifying?.();
                    try {
                        let verifyResponse;
                        try {
                            verifyResponse = await fetch(verifyUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    Accept: 'application/json',
                                    'Content-Type': 'application/json',
                                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                                },
                                body: JSON.stringify({ id_token: response.credential }),
                            });
                        } catch (_) {
                            throw makeError('Could not reach the server to verify Google sign-in. Check your connection and try again.', 'verification-network');
                        }
                        let data = {};
                        try {
                            data = await verifyResponse.json();
                        } catch (_) {}
                        if (!verifyResponse.ok) {
                            throw makeError(data.message || "Couldn't verify that Google account. Please try again.", 'verification-failed');
                        }

                        if (isActive && !isActive()) return;
                        if (!data.verified) {
                            clearIdentity();
                            onUnmatched?.(data);
                            return;
                        }

                        const claims = decodeCredential(response.credential);
                        const identity = {
                            email: data.email,
                            name: (typeof claims.name === 'string' && claims.name.trim()) || data.prefill?.name || '',
                            picture: pictureUrl(claims.picture),
                        };
                        saveIdentity(identity);
                        onVerified?.(data, identity);
                    } catch (error) {
                        if (isActive && !isActive()) return;
                        onError?.(error);
                    }
                },
            });
            googleIdentity.renderButton(container, {
                type: 'standard',
                theme: 'filled_black',
                size: 'large',
                text: 'continue_with',
                shape: 'pill',
            });
            onReady?.();
        }).catch((error) => {
            if (!isActive || isActive()) onError?.(error);
        });
    }

    global.GuestGoogleAccount = {
        clearIdentity,
        pictureUrl,
        readIdentity,
        renderVerifiedButton,
        saveIdentity,
    };
})(window);
