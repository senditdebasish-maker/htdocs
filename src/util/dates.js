'use strict';

/**
 * Date and Indian Financial Year helpers.
 *
 * Indian financial year: 01-Apr-YYYY to 31-Mar-(YYYY+1).
 * Label format: "2026-27".
 *
 * March/April classification: a date on or after 01-Apr belongs to FY YYYY-YY,
 * a date in Jan-Mar belongs to FY (YYYY-1)-YYYY. 31-Mar-2027 -> 2026-27,
 * 01-Apr-2027 -> 2027-28.
 */

const MS_DAY = 24 * 60 * 60 * 1000;

function pad2(n) {
  return String(n).padStart(2, '0');
}

/** Parse a "YYYY-MM-DD" string into a local Date at midnight. Returns null if invalid. */
function parseDate(s) {
  if (!s || typeof s !== 'string') return null;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s.trim());
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  if (Number.isNaN(d.getTime())) return null;
  if (d.getFullYear() !== Number(m[1]) || d.getMonth() !== Number(m[2]) - 1 || d.getDate() !== Number(m[3])) {
    return null; // e.g. 2026-02-30
  }
  return d;
}

function toISODate(d) {
  if (!d) return null;
  if (typeof d === 'string') {
    const p = parseDate(d);
    return p ? toISODate(p) : null;
  }
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

function todayISO() {
  return toISODate(new Date());
}

function nowISO() {
  return new Date().toISOString();
}

/** Financial year start year for a given date. */
function fyStartYear(date) {
  const d = typeof date === 'string' ? parseDate(date) || new Date(date) : date;
  return d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1;
}

/** Financial year label for a date, e.g. "2026-27". */
function fyLabel(date) {
  const y = fyStartYear(date);
  return `${y}-${String(y + 1).slice(-2)}`;
}

/** Parse a FY label "2026-27" -> {startYear, endYear, start, end, label}. */
function parseFY(label) {
  const m = /^(\d{4})-(\d{2})$/.exec(String(label || '').trim());
  if (!m) return null;
  const startYear = Number(m[1]);
  const endYear = Number(m[2]);
  const expectedEnd = Number(String(startYear + 1).slice(-2));
  if (endYear !== expectedEnd) return null;
  return {
    startYear,
    endYear: startYear + 1,
    start: `${startYear}-04-01`,
    end: `${startYear + 1}-03-31`,
    label,
  };
}

/** True if date is within financial year `fy` (label or object). */
function inFY(dateISO, fy) {
  const d = parseDate(dateISO);
  if (!d) return false;
  const f = typeof fy === 'string' ? parseFY(fy) : fy;
  if (!f) return false;
  const s = parseDate(f.start);
  const e = parseDate(f.end);
  return d >= s && d <= e;
}

/** Days between two ISO dates (b - a). */
function daysBetween(aISO, bISO) {
  const a = parseDate(aISO);
  const b = parseDate(bISO);
  if (!a || !b) return null;
  return Math.round((b - a) / MS_DAY);
}

/** Add days to an ISO date. */
function addDays(iso, days) {
  const d = parseDate(iso);
  if (!d) return null;
  const n = new Date(d.getTime() + days * MS_DAY);
  return toISODate(n);
}

function isBefore(aISO, bISO) {
  const a = parseDate(aISO);
  const b = parseDate(bISO);
  if (!a || !b) return false;
  return a < b;
}

function isAfter(aISO, bISO) {
  const a = parseDate(aISO);
  const b = parseDate(bISO);
  if (!a || !b) return false;
  return a > b;
}

/** Nice display of an ISO date, e.g. "06 Sep 2026". */
function formatDate(iso) {
  const d = parseDate(iso);
  if (!d) return '';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${pad2(d.getDate())} ${months[d.getMonth()]} ${d.getFullYear()}`;
}

function formatDateTime(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${pad2(d.getDate())} ${months[d.getMonth()]} ${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

module.exports = {
  parseDate,
  toISODate,
  todayISO,
  nowISO,
  fyStartYear,
  fyLabel,
  parseFY,
  inFY,
  daysBetween,
  addDays,
  isBefore,
  isAfter,
  formatDate,
  formatDateTime,
};
