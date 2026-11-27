const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync('public/js/shared/bookings-board.js', 'utf8');

function createFixture() {
    const listeners = new Map();
    const timers = [];
    const errors = [];
    const makeElement = (hidden = false) => ({
        hidden,
        attributes: {},
        listeners: {},
        textContent: '',
        classList: { remove() {} },
        addEventListener(type, handler) { this.listeners[type] = handler; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        querySelector(selector) { return this.queries?.[selector] || null; },
    });

    const workspace = makeElement();
    const panel = makeElement(false);
    const container = makeElement();
    const skeleton = makeElement(false);
    const status = makeElement();
    const errorState = makeElement(true);
    const message = makeElement();
    panel.queries = {
        '[data-calendar-loader="month"]': container,
        '[data-calendar-skeleton="month"]': skeleton,
    };
    container.queries = { '.court-calendar-loading-status': status };
    errorState.queries = { '[data-calendar-error-message]': message };
    workspace.queries = { '[data-calendar-error-state="month"]': errorState };

    const window = {
        location: { reload() {} },
        addEventListener(type, handler) { listeners.set(type, handler); },
        setTimeout(handler, delay) {
            timers.push({ handler, delay });
            return timers.length;
        },
        clearTimeout() {},
    };
    const document = {
        getElementById(id) {
            if (id === 'bookingsWorkspace') return workspace;
            if (id === 'courtBoardView') return makeElement(true);
            if (id === 'courtMonthView') return panel;
            if (id === 'courtWeekView') return makeElement(true);
            if (id === 'courtBoardData') throw new Error('simulated initialization failure');
            return null;
        },
    };
    const context = {
        document,
        window,
        console: { error(...args) { errors.push(args); } },
    };

    return { context, listeners, timers, errors, container, skeleton, errorState, message };
}

test('an initialization error replaces the visible server-rendered skeleton with Retry state', () => {
    const fixture = createFixture();
    assert.throws(() => vm.runInNewContext(source, fixture.context), /simulated initialization failure/);

    const error = new Error('calendar init failed');
    fixture.listeners.get('error')({ error });

    assert.equal(fixture.skeleton.hidden, true);
    assert.equal(fixture.container.attributes['aria-busy'], undefined);
    assert.equal(fixture.errorState.hidden, false);
    assert.match(fixture.message.textContent, /Retry/);
    assert.equal(fixture.errors[0][1], error);
});

test('the bootstrap safety timeout replaces a stuck initial skeleton with Retry state', () => {
    const fixture = createFixture();
    assert.throws(() => vm.runInNewContext(source, fixture.context), /simulated initialization failure/);

    fixture.timers[0].handler();

    assert.equal(fixture.timers[0].delay, 10000);
    assert.equal(fixture.skeleton.hidden, true);
    assert.equal(fixture.errorState.hidden, false);
    assert.match(fixture.message.textContent, /Retry/);
});
