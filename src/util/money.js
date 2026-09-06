'use strict';

/**
 * Decimal-safe financial arithmetic.
 *
 * The system NEVER uses JavaScript floating-point for money. All monetary
 * values are stored in SQLite as INTEGER minor units (paise).
 *
 * Convention (unambiguous):
 *   - `toMinor(rupees)`   : ₹ decimal (number|string) -> integer minor units.
 *   - `fromMinor(minor)`  : integer minor units -> "1234.56" string.
 *   - `addMinor/subMinor/mulMinor/qtyRateMinor/pctMinor` operate on and return
 *     integer minor units (never re-scale).
 */

const SCALE = 100; // 2 decimal places

function toNumber(value) {
  if (value === null || value === undefined || value === '') return 0;
  if (typeof value === 'bigint') return Number(value) / SCALE;
  if (typeof value === 'number') return value;
  const n = Number(String(value).replace(/[^\d.\-]/g, ''));
  return Number.isFinite(n) ? n : 0;
}

/** Convert a ₹ decimal amount (number|string) to integer minor units. */
function toMinor(value) {
  return Math.round(toNumber(value) * SCALE);
}

/** Convert integer minor units to a fixed decimal string ("1234.56"). */
function fromMinor(minor) {
  const m = Math.round(Number(minor) || 0);
  const neg = m < 0;
  const abs = Math.abs(m);
  const whole = Math.floor(abs / SCALE);
  const frac = abs % SCALE;
  return `${neg ? '-' : ''}${whole}.${String(frac).padStart(2, '0')}`;
}

/** Convert integer minor units to a float-like number (display/charts only). */
function fromMinorNum(minor) {
  return Number(fromMinor(minor));
}

/** Sum of integer minor units -> minor. */
function addMinor(...values) {
  return values.reduce((acc, v) => acc + Math.round(Number(v) || 0), 0);
}

function sumMinor(values) {
  return (values || []).reduce((acc, v) => acc + Math.round(Number(v) || 0), 0);
}

function subMinor(a, b) {
  return Math.round(Number(a) || 0) - Math.round(Number(b) || 0);
}

/** multiply a minor amount by a factor (fractional allowed) -> minor. */
function mulMinor(minor, factor) {
  return Math.round((Number(minor) || 0) * toNumber(factor));
}

/** Quantity × rate (rate in MINOR units) -> amount in minor units. */
function qtyRateMinor(quantity, rateMinor) {
  return Math.round((Number(quantity) || 0) * (Number(rateMinor) || 0));
}

/** percentage(baseMinor, pct) -> minor. pct is a plain number (e.g. 2.5). */
function pctMinor(baseMinor, pct) {
  return Math.round((Number(baseMinor) || 0) * (toNumber(pct) / 100));
}

/** percentage difference (value-base)/base*100 (inputs in minor) -> float for display. */
function pctDiff(baseMinor, valueMinor) {
  const b = Number(baseMinor) || 0;
  const v = Number(valueMinor) || 0;
  if (b === 0) return null;
  return ((v - b) / b) * 100;
}

/** Human-friendly Indian currency formatting for display ("₹12,34,567.89"). */
function formatINR(value) {
  const n = toNumber(value);
  const neg = n < 0;
  const abs = Math.abs(n).toFixed(2);
  const [whole, frac] = abs.split('.');
  let out = '';
  const w = whole;
  if (w.length <= 3) {
    out = w;
  } else {
    const last3 = w.slice(-3);
    let rest = w.slice(0, -3);
    const parts = [];
    while (rest.length > 2) {
      parts.unshift(rest.slice(-2));
      rest = rest.slice(0, -2);
    }
    if (rest) parts.unshift(rest);
    out = parts.join(',') + ',' + last3;
  }
  return `${neg ? '-' : ''}₹${out}.${frac}`;
}

/** Format a fixed minor-unit value with Indian grouping. */
function formatMinorINR(minor) {
  return formatINR(fromMinor(minor));
}

module.exports = {
  SCALE,
  toNumber,
  toMinor,
  fromMinor,
  fromMinorNum,
  addMinor,
  sumMinor,
  subMinor,
  mulMinor,
  qtyRateMinor,
  pctMinor,
  pctDiff,
  formatINR,
  formatMinorINR,
};
