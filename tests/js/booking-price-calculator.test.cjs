const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const calculatorModule = { exports: {} };
vm.runInNewContext(fs.readFileSync('public/js/shared/booking-price-calculator.js', 'utf8'), {
    module: calculatorModule,
    globalThis: {},
});
const calculator = calculatorModule.exports;

const fixture = JSON.parse(fs.readFileSync('tests/fixtures/booking-pricing-cases.json', 'utf8'));
for (const testCase of fixture.cases) {
    const result = calculator.interval(fixture.rules, 1, testCase.date, testCase.start, testCase.end);
    if (testCase.client_fallback) {
        assert.equal(result, null, `${testCase.name} should use the authoritative server preview`);
        continue;
    }
    assert.equal(result.total, testCase.expected, testCase.name);
}
for (const testCase of fixture.cases) {
    const oldSlot = calculator.interval(fixture.rules, 1, testCase.date, '09:00', '10:00');
    assert.ok(oldSlot, `${testCase.name} needs a verifiable existing slot`);
    const booking = {
        amount: oldSlot.total,
        court_id: 1,
        date: testCase.date,
        slots: [{ date: testCase.date, start: '09:00', end: '10:00', price: oldSlot.total }],
        equipment: [],
    };
    const estimate = calculator.calculateMove(fixture.rules, booking, {
        court_id: 1, date: testCase.date, start: testCase.start, end: testCase.end,
    });
    if (testCase.client_fallback) {
        assert.equal(estimate, null, `${testCase.name} should defer to the server quote`);
    } else {
        assert.equal(estimate, testCase.expected, `${testCase.name} move estimate`);
    }
}
const move = fixture.move_case;
const moved = Math.round((move.current_amount - move.old_slot_total + move.target_slot_total + Number.EPSILON) * 100) / 100;
assert.equal(moved, move.expected_amount, 'move total keeps the existing equipment add-on');
const ordinary = { amount: move.current_amount, court_id: 1, date: '2030-05-15', slots: [
    { date: '2030-05-15', start: '09:00', end: '10:00', price: 500 },
    { date: '2030-05-15', start: '10:00', end: '11:00', price: 500 },
], equipment: [{ price_each: move.equipment_total, quantity: 1 }] };
assert.equal(calculator.calculateMove(fixture.rules, ordinary, { court_id: 1, date: '2030-05-15', start: '17:00', end: '19:00' }), move.expected_amount);
assert.equal(calculator.calculateMove(fixture.rules, ordinary, { court_id: 1, date: '2030-05-15', start: '09:00', end: '11:00' }), move.current_amount, 'equal price bypasses the confirmation modal');
const override = { amount: 1301, court_id: 1, date: '2030-05-15', slots: [{ date: '2030-05-15', start: '16:00', end: '17:00', price: 500 }], equipment: [{ price_each: 100, quantity: 1 }] };
assert.equal(calculator.calculateMove(fixture.rules, override, { court_id: 1, date: '2030-05-15', start: '17:00', end: '18:00' }), null, 'manual override uses server preview');
process.stdout.write(`Passed ${fixture.cases.length} shared booking price fixtures and move checks.\n`);
