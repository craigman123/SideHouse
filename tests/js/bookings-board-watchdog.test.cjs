const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync('public/js/shared/bookings-board-watchdog.js', 'utf8');

function createFixture() {
    let timeoutHandler;
    let reloads = 0;
    const makeElement = (hidden = false) => ({
        hidden,
        dataset: {},
        attributes: {},
        listeners: {},
        textContent: '',
        addEventListener(type, handler) { this.listeners[type] = handler; },
        removeAttribute(name) { delete this.attributes[name]; },
        querySelector(selector) { return this.queries?.[selector] || null; },
    });
    const workspace = makeElement();
    const panel = makeElement(false);
    const container = makeElement();
    const skeleton = makeElement(false);
    const label = makeElement(false);
    const status = makeElement();
    const errorState = makeElement(true);
    const message = makeElement();
    panel.queries = {
        '[data-calendar-loader="month"]': container,
        '[data-calendar-skeleton="month"]': skeleton,
    };
    container.queries = {
        '[data-calendar-loading-label="month"]': label,
        '[data-calendar-loading-status="month"]': status,
    };
    errorState.queries = { '[data-calendar-error-message]': message };
    workspace.queries = { '[data-calendar-error-state="month"]': errorState };

    const window = {
        location: { reload() { reloads += 1; } },
        setTimeout(handler, delay) {
            assert.equal(delay, 10000);
            timeoutHandler = handler;
            return 1;
        },
    };
    const document = {
        getElementById(id) {
            if (id === 'bookingsWorkspace') return workspace;
            if (id === 'courtBoardView') return makeElement(true);
            if (id === 'courtMonthView') return panel;
            if (id === 'courtWeekView') return makeElement(true);
            return null;
        },
    };
    const context = {
        document,
        window,
        console: { error() {} },
    };

    return {
        context, workspace, container, skeleton, label, status, errorState, message,
        fireTimeout: () => timeoutHandler(),
        reloadCount: () => reloads,
    };
}

test('the external watchdog replaces a failed script load with an accessible retry state', () => {
    const fixture = createFixture();
    vm.runInNewContext(source, fixture.context);
    fixture.fireTimeout();

    assert.equal(fixture.skeleton.hidden, true);
    assert.equal(fixture.label.hidden, true);
    assert.equal(fixture.status.textContent, '');
    assert.equal(fixture.container.attributes['aria-busy'], undefined);
    assert.equal(fixture.errorState.hidden, false);
    assert.match(fixture.message.textContent, /Retry/);

    const retryButton = {
        closest(selector) {
            if (selector === '[data-calendar-retry]') return this;
            if (selector === '.court-calendar-error-state') return fixture.errorState;
            return null;
        },
    };
    let defaultPrevented = false;
    let propagationStopped = false;
    fixture.workspace.listeners.click({
        target: retryButton,
        preventDefault() { defaultPrevented = true; },
        stopImmediatePropagation() { propagationStopped = true; },
    });

    assert.equal(defaultPrevented, true);
    assert.equal(propagationStopped, true);
    assert.equal(fixture.reloadCount(), 1);
});
