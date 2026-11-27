document.addEventListener('DOMContentLoaded', async () => {
    const badge = document.querySelector('[data-lumi-badge]');
    const lumiButton = document.querySelector('[data-user-lumi-button]');
    const popover = document.querySelector('[data-lumi-popover]');
    const primaryContainer = popover?.querySelector('[data-lumi-popover-primary]');
    const secondaryContainer = popover?.querySelector('[data-lumi-popover-secondary]');
    const closeButton = popover?.querySelector('[data-lumi-popover-close]');
    const dismissButton = popover?.querySelector('[data-lumi-not-interested]');
    const errorMessage = popover?.querySelector('[data-lumi-popover-error]');
    const feed = document.querySelector('.lumi-suggestion-feed');
    const suggestionsUrl = badge?.dataset.suggestionsUrl;
    const dismissUrl = popover?.dataset.dismissUrl || badge?.dataset.dismissUrl;
    const svgNamespace = 'http://www.w3.org/2000/svg';
    let currentSuggestions = [];
    let openTimer = null;

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

    function formatPrice(value) {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) return '';
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 2,
        }).format(Number(value));
    }

    function appendDetails(container, suggestion, compact = false) {
        const details = suggestion.details || {};
        const values = [formatDate(details.date), formatTime(details.time)];
        if (suggestion.type === 'open_play') {
            values.push(formatPrice(details.price));
            if (Number.isFinite(Number(details.spots_left))) {
                values.push(`${Number(details.spots_left)} spots left`);
            }
        } else if (typeof details.court === 'string') {
            values.push(details.court);
        }

        values.filter(Boolean).forEach((value) => {
            const item = document.createElement('span');
            item.textContent = value;
            container.append(item);
        });
        if (compact) container.classList.add('is-compact');
    }

    function safeBookingUrl(value) {
        if (typeof value !== 'string') return null;
        try {
            const target = new URL(value, window.location.origin);
            if (target.origin !== window.location.origin || target.pathname !== '/book') return null;
            return `${target.pathname}${target.search}`;
        } catch (_) {
            return null;
        }
    }

    function createBookingLink(suggestion, compact = false) {
        const href = safeBookingUrl(suggestion.cta_url);
        if (!href) return null;
        const link = document.createElement('a');
        link.className = compact ? 'user-lumi-popover-secondary-link' : 'user-lumi-popover-cta';
        link.href = href;
        link.textContent = suggestion.cta_label;
        link.dataset.lumiCta = '';
        return link;
    }

    function createPrimary(suggestion) {
        const article = document.createElement('article');
        article.className = 'user-lumi-popover-suggestion';
        article.dataset.suggestionKey = suggestion.key;

        const heading = document.createElement('h2');
        if (suggestion.type === 'open_play') {
            heading.classList.add('user-lumi-popover-has-icon');
            heading.append(window.SideHouseCardIcons.create('padel'), document.createTextNode(suggestion.title));
        } else {
            heading.textContent = suggestion.title;
        }
        const message = document.createElement('p');
        message.textContent = suggestion.message;
        const details = document.createElement('div');
        details.className = 'user-lumi-popover-details';
        appendDetails(details, suggestion);
        const link = createBookingLink(suggestion);
        if (!link) return null;

        article.append(heading, message, details, link);
        return article;
    }

    function createSecondary(suggestion) {
        const article = document.createElement('article');
        article.dataset.suggestionKey = suggestion.key;

        const heading = document.createElement('h3');
        if (suggestion.type === 'open_play') {
            heading.classList.add('user-lumi-popover-has-icon');
            heading.append(window.SideHouseCardIcons.create('padel'), document.createTextNode(suggestion.title));
        } else {
            heading.textContent = suggestion.title;
        }
        const details = document.createElement('div');
        details.className = 'user-lumi-popover-details';
        appendDetails(details, suggestion, true);
        const link = createBookingLink(suggestion, true);
        if (!link) return null;

        article.append(heading, details, link);
        return article;
    }

    function positionPopover() {
        if (!popover || !lumiButton || popover.hidden) return;
        const anchor = lumiButton.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const mobile = window.matchMedia('(max-width: 600px)').matches;
        const width = mobile
            ? Math.max(0, viewportWidth - 16)
            : Math.min(420, Math.max(0, viewportWidth - 32));
        const left = mobile
            ? 8
            : Math.max(16, Math.min(
                anchor.left + anchor.width / 2 - width / 2,
                viewportWidth - width - 16,
            ));
        const caretX = Math.max(20, Math.min(anchor.left + anchor.width / 2 - left, width - 20));
        const popupHeight = Math.min(popover.scrollHeight, window.innerHeight * 0.8, 560);
        const top = Math.max(8, Math.min(
            anchor.bottom + 10,
            window.innerHeight - popupHeight - 8,
        ));

        popover.style.width = `${width}px`;
        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
        popover.style.setProperty('--lumi-caret-left', `${caretX}px`);
    }

    function openPopover() {
        if (!popover || currentSuggestions.length === 0) return;
        popover.hidden = false;
        popover.setAttribute('aria-hidden', 'false');
        lumiButton?.setAttribute('aria-expanded', 'true');
        positionPopover();
        popover.getBoundingClientRect();
        window.requestAnimationFrame(() => popover.classList.add('is-open'));
        window.addEventListener('resize', positionPopover);
        window.addEventListener('scroll', positionPopover, true);
    }

    function closePopover(returnFocus = false) {
        window.clearTimeout(openTimer);
        openTimer = null;
        if (!popover || popover.hidden) return;
        popover.classList.remove('is-open');
        popover.hidden = true;
        popover.setAttribute('aria-hidden', 'true');
        lumiButton?.setAttribute('aria-expanded', 'false');
        window.removeEventListener('resize', positionPopover);
        window.removeEventListener('scroll', positionPopover, true);
        if (returnFocus) lumiButton?.focus();
    }

    function renderPopover(suggestions) {
        if (!popover || !primaryContainer || !secondaryContainer) return false;
        currentSuggestions = suggestions.filter((suggestion) => safeBookingUrl(suggestion.cta_url)).slice(0, 3);
        const primary = currentSuggestions[0];
        const primaryElement = primary ? createPrimary(primary) : null;
        if (!primaryElement) {
            currentSuggestions = [];
            return false;
        }

        primaryContainer.replaceChildren(primaryElement);
        secondaryContainer.replaceChildren(
            ...currentSuggestions.slice(1).map(createSecondary).filter(Boolean),
        );
        if (errorMessage) errorMessage.hidden = true;
        if (dismissButton) dismissButton.disabled = false;
        return true;
    }

    async function sendDismissal(key) {
        if (!dismissUrl) throw new Error('Dismissal is unavailable.');
        const response = await fetch(dismissUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ key }),
        });
        if (!response.ok) throw new Error('Dismissal failed.');
    }

    async function dismissPrimarySuggestion() {
        const suggestion = currentSuggestions[0];
        if (!suggestion || !dismissButton) return;
        dismissButton.disabled = true;
        if (errorMessage) errorMessage.hidden = true;

        try {
            await sendDismissal(suggestion.key);
            const remaining = currentSuggestions.slice(1);
            if (remaining.length === 0 || !renderPopover(remaining)) {
                if (badge) badge.hidden = true;
                closePopover(true);
                return;
            }
            positionPopover();
        } catch (_) {
            if (errorMessage) {
                errorMessage.textContent = 'Could not hide this suggestion. Please try again.';
                errorMessage.hidden = false;
            }
            dismissButton.disabled = false;
        }
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

    feed?.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest('[data-lumi-dismiss]');
        const card = button?.closest('[data-suggestion-key]');
        const error = card?.querySelector('[data-lumi-dismiss-error]');
        if (!button || !card || !dismissUrl) return;
        button.disabled = true;
        if (error) error.hidden = true;

        try {
            await sendDismissal(card.dataset.suggestionKey);
            card.remove();
            if (!feed.querySelector('[data-suggestion-key]')) addEmptyState();
        } catch (_) {
            if (error) {
                error.textContent = 'Could not hide this suggestion. Please try again.';
                error.hidden = false;
            }
            button.disabled = false;
        }
    });

    closeButton?.addEventListener('click', () => closePopover(true));
    dismissButton?.addEventListener('click', dismissPrimarySuggestion);

    popover?.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('[data-lumi-cta], [data-lumi-see-all]')) {
            if (badge) badge.hidden = true;
            closePopover(false);
        }
    });

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        if (target.closest('[data-user-lumi-button]')) {
            closePopover(false);
        } else if (popover && !popover.hidden && !popover.contains(target)) {
            closePopover(false);
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && popover && !popover.hidden) {
            event.preventDefault();
            closePopover(true);
        }
    });

    if (!suggestionsUrl || !popover) return;

    try {
        const response = await fetch(suggestionsUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions : [];
        if (badge) badge.hidden = !payload.has_unseen || window.location.pathname === '/lumi';

        if (payload.show_login_popover === true
            && suggestions.length > 0
            && window.location.pathname !== '/lumi'
            && renderPopover(suggestions)) {
            openTimer = window.setTimeout(openPopover, 600);
        }
    } catch (_) {
        if (badge) badge.hidden = true;
    }
});
