const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync('public/js/shared/bookings-board.js', 'utf8');

test('calendar grid event initialization declares and attaches every grid without ReferenceError', () => {
    const wiring = source.match(
        /    const calendarGrids = \[board, monthGrid, weekGrid\]\.filter\(Boolean\);\r?\n    calendarGrids\.forEach\(attachCalendarGridEvents\);/
    );
    assert.ok(wiring, 'calendarGrids must be declared before initialization uses it');

    const attached = [];
    assert.doesNotThrow(() => vm.runInNewContext(wiring[0], {
        board: { name: 'board' },
        monthGrid: { name: 'month' },
        weekGrid: { name: 'week' },
        attachCalendarGridEvents(grid) { attached.push(grid.name); },
    }));
    assert.deepEqual(attached, ['board', 'month', 'week']);
});
