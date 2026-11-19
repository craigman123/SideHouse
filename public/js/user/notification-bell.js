/**
 * Bell-icon unread indicator. Include this on every authenticated page
 * that renders the nav (i.e. the shared layout), not just the
 * notifications page itself — the whole point is the guest sees the red
 * dot before they ever click in.
 *
 * Expects two things already in the markup (see the notes sent alongside
 * this file for exactly where to add them to your existing bell <a>):
 *   1. The bell link has: data-unread-url="{{ route('user.notifications.unread-count') }}"
 *   2. Inside that same <a>, an empty <span class="bell-unread-dot"></span>
 *      sitting next to (not replacing) the bell SVG.
 *
 * If either is missing, this script quietly does nothing — it doesn't
 * throw, so it's safe to include even before the markup catches up.
 */
document.addEventListener('DOMContentLoaded', () => {
    const bellLink = document.querySelector('a[data-unread-url]');
    const dot = bellLink?.querySelector('.bell-unread-dot');
    if (!bellLink || !dot) return;

    const POLL_INTERVAL_MS = 30000;

    async function refreshUnreadDot() {
        try {
            const res = await fetch(bellLink.dataset.unreadUrl, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();
            dot.hidden = !(data.unread > 0);
        } catch (err) {
            // Silent on purpose — a failed poll just leaves the dot in
            // whatever state it was already in, not worth surfacing to
            // the guest as an error over something this minor.
            console.error('Unread notification check failed:', err);
        }
    }

    refreshUnreadDot();
    setInterval(refreshUnreadDot, POLL_INTERVAL_MS);

    // Visiting the notifications page is the one moment we can update
    // the dot instantly rather than waiting for the next poll — no
    // reason to leave a stale red dot up for up to 30s after they've
    // already read everything.
    if (window.location.pathname.endsWith('/notifications')) {
        dot.hidden = true;
    }
});
