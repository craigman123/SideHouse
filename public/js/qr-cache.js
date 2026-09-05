// qr-cache.js - QR Code caching utility for payment-waiting pages

(function() {
    'use strict';

    /**
     * QR Cache Manager - Handles storing and retrieving QR codes in sessionStorage
     */
    const QRCache = {
        getKey(bookingId) {
            return `qr_${bookingId}`;
        },

        save(bookingId, qrImageUrl, expiresAt = null) {
            try {
                const key = this.getKey(bookingId);
                const data = {
                    qr_image_url: qrImageUrl,
                    expires_at: expiresAt,
                    cached_at: new Date().toISOString()
                };
                sessionStorage.setItem(key, JSON.stringify(data));
                return true;
            } catch (e) {
                console.warn('Failed to cache QR:', e);
                return false;
            }
        },

        load(bookingId) {
            try {
                const key = this.getKey(bookingId);
                const cached = sessionStorage.getItem(key);
                if (!cached) return null;

                const data = JSON.parse(cached);
                
                // ✅ DON'T check expiry here - only check if QR exists
                // The QR image itself doesn't expire, only the booking does
                if (!data.qr_image_url) {
                    this.clear(bookingId);
                    return null;
                }

                return data;
            } catch (e) {
                console.warn('Failed to load QR from cache:', e);
                return null;
            }
        },

        clear(bookingId) {
            try {
                const key = this.getKey(bookingId);
                sessionStorage.removeItem(key);
                return true;
            } catch (e) {
                return false;
            }
        },

        clearAll() {
            try {
                const keys = Object.keys(sessionStorage);
                keys.forEach(key => {
                    if (key.startsWith('qr_')) {
                        sessionStorage.removeItem(key);
                    }
                });
                return true;
            } catch (e) {
                return false;
            }
        }
    };

    // ============================================================
    // QR Loader
    // ============================================================

    class QRLoader {
        constructor(options) {
            this.bookingId = options.bookingId;
            this.token = options.token;
            this.createQrUrl = options.createQrUrl;
            this.statusUrl = options.statusUrl;
            this.landingUrl = options.landingUrl;
            this.csrfHeaders = options.csrfHeaders || (() => ({}));
            
            // DOM Elements
            this.qrImageEl = options.qrImageEl || document.getElementById('qrImage');
            this.qrLoadingEl = options.qrLoadingEl || document.getElementById('qrLoading');
            this.qrImageWrapEl = options.qrImageWrapEl || document.getElementById('qrImageWrap');
            this.qrErrorEl = options.qrErrorEl || document.getElementById('qrError');
            this.qrStatusLineEl = options.qrStatusLineEl || document.getElementById('qrStatusLine');
            this.qrRetryBtn = options.qrRetryBtn || document.getElementById('qrRetryBtn');
            
            // State
            this.isLoading = false;
            
            // Bind methods
            this.load = this.load.bind(this);
            this.retry = this.retry.bind(this);
            
            // Setup retry button
            if (this.qrRetryBtn) {
                this.qrRetryBtn.addEventListener('click', this.retry);
            }
        }

        showLoading() {
            if (this.qrLoadingEl) this.qrLoadingEl.hidden = false;
            if (this.qrImageWrapEl) this.qrImageWrapEl.hidden = true;
            if (this.qrErrorEl) this.qrErrorEl.hidden = true;
            if (this.qrStatusLineEl) this.qrStatusLineEl.hidden = true;
        }

        showQR(qrImageUrl) {
            if (this.qrImageEl) {
                this.qrImageEl.src = qrImageUrl;
            }
            if (this.qrLoadingEl) this.qrLoadingEl.hidden = true;
            if (this.qrImageWrapEl) this.qrImageWrapEl.hidden = false;
            if (this.qrStatusLineEl) this.qrStatusLineEl.hidden = false;
            if (this.qrErrorEl) this.qrErrorEl.hidden = true;
        }

        showError(message = 'Couldn\'t generate your QR code.') {
            if (this.qrLoadingEl) this.qrLoadingEl.hidden = true;
            if (this.qrErrorEl) {
                this.qrErrorEl.hidden = false;
                const textNode = this.qrErrorEl.childNodes[0];
                if (textNode && textNode.nodeType === Node.TEXT_NODE) {
                    textNode.textContent = message;
                }
            }
            if (this.qrImageWrapEl) this.qrImageWrapEl.hidden = true;
            if (this.qrStatusLineEl) this.qrStatusLineEl.hidden = true;
        }

        redirectToLanding(message, isError = true) {
            const param = isError ? 'booking_error' : 'booking_success';
            const params = new URLSearchParams({ [param]: message });
            window.location.href = `${this.landingUrl}?${params.toString()}`;
        }

        async load() {
            // Prevent multiple simultaneous loads
            if (this.isLoading) return;
            this.isLoading = true;

            try {
                // Step 1: Try cache first - NO EXPIRY CHECK
                const cached = QRCache.load(this.bookingId);
                if (cached && cached.qr_image_url) {
                    this.showQR(cached.qr_image_url);
                    this.isLoading = false;
                    return;
                }

                // Step 2: Show loading
                this.showLoading();

                // Step 3: Check booking status first
                const statusRes = await fetch(`${this.statusUrl}?token=${encodeURIComponent(this.token)}`, {
                    headers: { Accept: 'application/json' },
                });
                const statusData = await statusRes.json();

                if (statusData.status === 'paid') {
                    this.redirectToLanding('Payment confirmed — you\'re all set!', false);
                    this.isLoading = false;
                    return;
                }

                if (statusData.status === 'cancelled') {
                    this.redirectToLanding('This booking was cancelled.', true);
                    this.isLoading = false;
                    return;
                }

                // Step 4: Get or generate QR - SINGLE CALL
                const res = await fetch(this.createQrUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...this.csrfHeaders(),
                    },
                    body: JSON.stringify({
                        booking_id: this.bookingId,
                        token: this.token
                    }),
                });

                const data = await res.json().catch(() => ({}));

                // ✅ Handle 409 - QR already exists, just reload to get it
                if (res.status === 409 && data.payment_intent_id) {
                    // Clear cache and reload
                    QRCache.clear(this.bookingId);
                    window.location.reload();
                    this.isLoading = false;
                    return;
                }

                if (!res.ok || !data.qr_image_url) {
                    throw new Error(data.message || 'QR generation failed');
                }

                // ✅ Cache and show QR - NO EXPIRY CHECK
                QRCache.save(this.bookingId, data.qr_image_url);
                this.showQR(data.qr_image_url);

            } catch (err) {
                console.error('QR loading error:', err);
                // ✅ Only show error if we couldn't load from cache either
                const cached = QRCache.load(this.bookingId);
                if (cached && cached.qr_image_url) {
                    // Fallback to cache even if it's "expired"
                    this.showQR(cached.qr_image_url);
                } else {
                    this.showError('Couldn\'t load your QR code. Please try again.');
                }
            } finally {
                this.isLoading = false;
            }
        }

        retry() {
            QRCache.clear(this.bookingId);
            this.load();
        }
    }

    // ============================================================
    // AUTO-INIT
    // ============================================================

    function initQRLoader() {
        const box = document.getElementById('waitingPage');
        if (!box) return;

        const bookingId = box.dataset.bookingId;
        const token = box.dataset.token; // undefined on the user page — fine,
        // createQr() falls back to auth-ownership when there's no token.

        if (!bookingId) return;

        function getCsrfHeaders() {
            const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (metaToken) return { 'X-CSRF-TOKEN': metaToken };

            const cookieToken = getCookie('XSRF-TOKEN');
            if (cookieToken) return { 'X-XSRF-TOKEN': cookieToken };

            return {};
        }

        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
            return match ? decodeURIComponent(match[1]) : null;
        }

        const qrLoader = new QRLoader({
            bookingId: bookingId,
            token: token,
            createQrUrl: box.dataset.createQrUrl,
            statusUrl: box.dataset.statusUrl,
            landingUrl: box.dataset.landingUrl,
            csrfHeaders: getCsrfHeaders,
        });

        qrLoader.load();
        window.__qrLoader = qrLoader;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQRLoader);
    } else {
        initQRLoader();
    }

    window.QRCache = QRCache;

})();