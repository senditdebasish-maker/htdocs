'use strict';

const test = require('node:test');
const assert = require('node:assert');
const dates = require('../../src/util/dates');

test('financial year label for dates (March/April boundary)', () => {
  assert.strictEqual(dates.fyLabel('2026-03-31'), '2025-26'); // March belongs to previous FY
  assert.strictEqual(dates.fyLabel('2026-04-01'), '2026-27'); // April starts new FY
  assert.strictEqual(dates.fyLabel('2027-01-15'), '2026-27');
  assert.strictEqual(dates.fyLabel('2027-03-31'), '2026-27');
  assert.strictEqual(dates.fyLabel('2027-04-01'), '2027-28');
});

test('parseFY validation', () => {
  assert.deepStrictEqual(dates.parseFY('2026-27'), { startYear: 2026, endYear: 2027, start: '2026-04-01', end: '2027-03-31', label: '2026-27' });
  assert.strictEqual(dates.parseFY('2026-28'), null); // wrong end year
  assert.strictEqual(dates.parseFY('bad'), null);
});

test('inFY membership', () => {
  assert.strictEqual(dates.inFY('2026-06-01', '2026-27'), true);
  assert.strictEqual(dates.inFY('2026-03-31', '2026-27'), false);
  assert.strictEqual(dates.inFY('2027-03-31', '2026-27'), true);
});

test('daysBetween and addDays', () => {
  assert.strictEqual(dates.daysBetween('2026-07-01', '2026-07-15'), 14);
  assert.strictEqual(dates.addDays('2026-07-01', 14), '2026-07-15');
});

test('date sequence validation helpers', () => {
  assert.strictEqual(dates.isBefore('2026-07-01', '2026-07-15'), true);
  assert.strictEqual(dates.isAfter('2026-07-15', '2026-07-01'), true);
});

test('parseDate rejects invalid dates', () => {
  assert.strictEqual(dates.parseDate('2026-02-30'), null);
  assert.strictEqual(dates.parseDate('not-a-date'), null);
  assert.strictEqual(dates.parseDate('2026-13-01'), null);
});
