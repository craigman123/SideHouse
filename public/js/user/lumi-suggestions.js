document.addEventListener('DOMContentLoaded', async () => {
    const badge = document.querySelector('[data-lumi-badge]');
    const loginCard = document.querySelector('[data-lumi-login-card]');
    const loginItems = loginCard?.querySelector('[data-lumi-login-items]');
    const loginDismiss = loginCard?.querySelector('[data-lumi-login-dismiss]');
    const feed = document.querySelector('.lumi-suggestion-feed');
    const suggestionsUrl = badge?.dataset.suggestionsUrl || loginCard?.dataset.suggestionsUrl;
    const dismissUrl = badge?.dataset.dismissUrl;

    const svgNamespace = 'http://www.w3.org/2000/svg';
    const iconPaths = {
        free_slot_same_day: ['M16 2v4M8 2v4M3 9h18M12 12v4l2 1'],
        weekday_vacancy: ['M12 2v2M12 20v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42'],
        open_play: ['M9 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6', 'M3 20v-1a6 6 0 0 1 12 0v1M16 5.5a3 3 0 0 1 0 5.8M18 14a5 5 0 0 1 3 4.6V20'],
        first_booking: ['M12 3 14.8 9.2 21 12l-6.2 2.8L12 21l-2.8-6.2L3 12l6.2-2.8L12 3Z'],
    };

    function createIcon(type) {
        const svg = document.createElementNS(svgNamespace, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        const paths = iconPaths[type] || iconPaths.first_booking;
        if (type === 'free_slot_same_day') {
            const rect = document.createElementNS(svgNamespace, 'rect');
            rect.setAttribute('x', '3');
            rect.setAttribute('y', '4');
            rect.setAttribute('width', '18');
            rect.setAttribute('height', '17');
            rect.setAttribute('rx', '2');
            svg.append(rect);
        }
        if (type === 'weekday_vacancy') {
            const circle = document.createElementNS(svgNamespace, 'circle');
            circle.setAttribute('cx', '12');
            circle.setAttribute('cy', '12');
            circle.setAttribute('r', '4');
            svg.append(circle);
        }
        paths.forEach((value) => {
            const path = document.createElementNS(svgNamespace, 'path');
            path.setAttribute('d', value);
            svg.append(path);
        });
        return svg;
    }

    function formatDate(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, month - 1, day).toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    }

    function formatTime(value) {
        if (typeof value !== 'string' || !/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) return '';
        const [hours, minutes] = value.split(':').map(Number);
        const suffix = hours >= 12 ? 'PM' : 'AM';
        return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${suffix}`;
    }

    function appendDetails(container, details) {
        const values = [
            formatDate(details.date),
            formatTime(details.time),
            details.court,
            Number.isFinite(Number(details.price))
                ? new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP' }).format(Number(details.price))
                : '',
            Number.isFinite(Number(details.spots_left)) ? `${Number(details.spots_left)} spots left` : '',
        ].filter(Boolean);

        values.forEach((value) => {
            const item = document.createElement('span');
            item.textContent = value;
            container.append(item);
        });
    }

    function safeBookingUrl(value) {
        if (typeof value !== 'string') return null;
        try {
            const target = new URL(value, window.location.origin);
            return target.origin === window.location.origin ? `${target.pathname}${target.search}` : null;
        } catch (_) {
            return null;
        }
    }

    function createSuggestionCard(suggestion, compact = false) {
        const card = document.createElement(compact ? 'article' : 'article');
        card.className = compact ? 'lumi-login-suggestion' : 'lumi-suggestion-card';
        if (!compact) {
            card.dataset.suggestionKey = suggestion.key;
            card.dataset.suggestionType = suggestion.type;
            const icon = document.createElement('div');
            icon.className = 'lumi-suggestion-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.append(createIcon(suggestion.type));
            card.append(icon);
        }

        const content = document.createElement('div');
        if (!compact) content.className = 'lumi-suggestion-content';
        const title = document.createElement('h3');
        title.textContent = suggestion.title;
        const message = document.createElement('p');
        message.textContent = suggestion.message;
        const details = document.createElement('div');
        details.className = 'lumi-suggestion-details';
        appendDetails(details, suggestion.details || {});

        const ctaUrl = safeBookingUrl(suggestion.cta_url);
        const cta = document.createElement('a');
        cta.className = 'lumi-suggestion-cta';
        cta.textContent = suggestion.cta_label;
        if (ctaUrl) {
            cta.href = ctaUrl;
        } else {
            cta.hidden = true;
        }

        content.append(title);
        if (compact) {
            content.append(message, details);
            card.append(content, cta);
        } else {
            const actions = document.createElement('div');
            actions.className = 'lumi-suggestion-actions';
            actions.append(cta);
            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'lumi-dismiss-button';
            dismiss.dataset.lumiDismiss = '';
            dismiss.setAttribute('aria-label', `Not interested in: ${suggestion.title}`);
            dismiss.textContent = 'Not interested';
            actions.append(dismiss);
            const error = document.createElement('p');
            error.className = 'lumi-dismiss-error';
            error.dataset.lumiDismissError = '';
            error.setAttribute('role', 'status');
            error.hidden = true;
            content.append(message, details, actions, error);
            card.append(content);
        }
        return card;
    }

    function addEmptyState() {
        if (!feed || feed.querySelector('.lumi-empty-state')) return;
        const empty = document.createElement('div');
        empty.className = 'lumi-empty-state';
        const message = document.createElement('p');
        message.textContent = 'Nothing new right now. Check back after your next game.';
        empty.append(message);
        feed.append(empty);
    }

    async function dismissSuggestion(button) {
        const card = button.closest('[data-suggestion-key]');
        const error = card?.querySelector('[data-lumi-dismiss-error]');
        if (!card || !dismissUrl) return;
        button.disabled = true;
        if (error) error.hidden = true;

        try {
            const response = await fetch(dismissUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ key: card.dataset.suggestionKey }),
            });
            if (!response.ok) throw new Error('Dismissal failed.');
            card.remove();
            if (feed && !feed.querySelector('[data-suggestion-key]')) addEmptyState();
        } catch (_) {
            if (error) {
                error.textContent = 'Could not hide this suggestion. Please try again.';
                error.hidden = false;
            }
            button.disabled = false;
        }
    }

    feed?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-lumi-dismiss]');
        if (button) dismissSuggestion(button);
    });

    loginDismiss?.addEventListener('click', () => {
        loginCard.hidden = true;
    });

    if (!suggestionsUrl) return;

    try {
        const response = await fetch(suggestionsUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Suggestions could not be loaded.');
        const payload = await response.json();
        if (badge) badge.hidden = !payload.has_unseen || window.location.pathname === '/lumi';

        if (loginCard && loginItems && loginCard.dataset.showOnce === 'true') {
            const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions.slice(0, 3) : [];
            suggestions.forEach((suggestion) => loginItems.append(createSuggestionCard(suggestion, true)));
            loginCard.hidden = suggestions.length === 0;
        }
    } catch (_) {
        if (loginCard && loginCard.dataset.showOnce === 'true') {
            const error = document.createElement('p');
            error.className = 'lumi-dismiss-error';
            error.textContent = 'Lumi suggestions could not be loaded. Please try again later.';
            loginItems?.append(error);
            loginCard.hidden = false;
        }
    }
});
