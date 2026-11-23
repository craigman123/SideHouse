import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../public/js/shared/user-access.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../public/css/shared/skeletons.css', import.meta.url), 'utf8');
const helper = source.match(/    function setDetailsLoadState\(loadState\) \{[\s\S]*?\n    \}/)?.[0];
assert.ok(helper, 'the shared details load-state controller must exist');

function createContext() {
    const form = { dataset: {} };
    const inputs = Object.fromEntries(['name', 'username', 'email', 'phone_number'].map((key) => [key, { disabled: true }]));
    const skeletons = Object.fromEntries(Object.keys(inputs).map((key) => [key, { hidden: false }]));
    const elements = {
        continueAccountDetails: { disabled: true },
        accountDetailsError: { hidden: false },
        retryAccountDetails: { hidden: false },
    };
    const context = {
        detailsForm: form,
        detailsInputs: inputs,
        document: {
            querySelector(selector) {
                const key = selector.match(/data-details-skeleton="([^"]+)"/)?.[1];
                return key ? skeletons[key] : null;
            },
            getElementById(id) { return elements[id]; },
        },
    };
    return { context, form, inputs, skeletons, elements };
}

test('loaded details state hides every skeleton and enables all fields and Continue', () => {
    const fixture = createContext();
    runInNewContext(`${helper}\nsetDetailsLoadState('loaded');`, fixture.context);

    assert.equal(fixture.form.dataset.loadState, 'loaded');
    assert.ok(Object.values(fixture.skeletons).every((skeleton) => skeleton.hidden));
    assert.ok(Object.values(fixture.inputs).every((input) => !input.disabled));
    assert.equal(fixture.elements.continueAccountDetails.disabled, false);
    assert.equal(fixture.elements.accountDetailsError.hidden, true);
    assert.equal(fixture.elements.retryAccountDetails.hidden, true);
});

test('error details state hides skeletons and keeps fields and Continue disabled', () => {
    const fixture = createContext();
    runInNewContext(`${helper}\nsetDetailsLoadState('error');`, fixture.context);

    assert.equal(fixture.form.dataset.loadState, 'error');
    assert.ok(Object.values(fixture.skeletons).every((skeleton) => skeleton.hidden));
    assert.ok(Object.values(fixture.inputs).every((input) => input.disabled));
    assert.equal(fixture.elements.continueAccountDetails.disabled, true);
});

test('hidden skeletons cannot be made visible by the shared display rule', () => {
    assert.match(styles, /\.skeleton\[hidden\][\s\S]*?display:\s*none\s*!important/);
});

test('autocomplete final states replace or clear skeleton row children', () => {
    const resultsFunction = source.match(/function showCandidateResults\(items\) \{([\s\S]*?)\n    \}/)?.[1];
    assert.ok(resultsFunction);
    assert.match(resultsFunction, /candidateResults\.replaceChildren\(\)/);

    const errorHandler = source.slice(source.indexOf('} catch (candidateError) {'), source.indexOf('function setDialogError'));
    assert.match(errorHandler, /candidateResults\.replaceChildren\(\)/);
});
