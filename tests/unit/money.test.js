'use strict';

const test = require('node:test');
const assert = require('node:assert');
const money = require('../../src/util/money');

test('toMinor/fromMinor round-trip', () => {
  assert.strictEqual(money.toMinor('1234.56'), 123456);
  assert.strictEqual(money.fromMinor(123456), '1234.56');
  assert.strictEqual(money.toMinor(0), 0);
  assert.strictEqual(money.fromMinor(0), '0.00');
  assert.strictEqual(money.toMinor('0.1'), 10);
  assert.strictEqual(money.fromMinor(10), '0.10');
});

test('negative amounts', () => {
  assert.strictEqual(money.fromMinor(money.toMinor('-50.5')), '-50.50');
});

test('qtyRateMinor uses minor-unit rate without re-scaling', () => {
  // 100 units @ ₹250.00 = ₹25,000.00 = 2,500,000 paise
  assert.strictEqual(money.qtyRateMinor(100, 25000), 2500000);
  assert.strictEqual(money.qtyRateMinor(50.5, 100), 5050);
});

test('pctMinor operates on minor base', () => {
  assert.strictEqual(money.pctMinor(1000000, 5), 50000); // 5% of ₹10,000
  assert.strictEqual(money.pctMinor(999, 10), 100); // rounding
});

test('addMinor/sumMinor/subMinor/mulMinor', () => {
  assert.strictEqual(money.addMinor(100, 200, 300), 600);
  assert.strictEqual(money.sumMinor([100, 50]), 150);
  assert.strictEqual(money.subMinor(100, 30), 70);
  assert.strictEqual(money.mulMinor(500, 2), 1000);
});

test('pctDiff', () => {
  assert.strictEqual(money.pctDiff(100000000, 95000000).toFixed(2), '-5.00');
  assert.strictEqual(money.pctDiff(0, 10), null);
});

test('Indian currency formatting', () => {
  assert.strictEqual(money.formatINR(1234567.89), '₹12,34,567.89');
  assert.strictEqual(money.formatMinorINR(123456789), '₹12,34,567.89');
});

test('floating point precision avoided (0.1 + 0.2 style)', () => {
  const a = money.toMinor('0.10');
  const b = money.toMinor('0.20');
  assert.strictEqual(money.fromMinor(a + b), '0.30');
});
