'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const dates = require('../util/dates');
const { validation, notFound, badRequest, conflict } = require('../util/errors');

/** Get current FY (is_current=1, else computed from today). */
function getCurrentFY() {
  const cur = db.get('SELECT * FROM financial_years WHERE is_current = 1');
  if (cur) return cur;
  const label = dates.fyLabel(new Date());
  const f = db.get('SELECT * FROM financial_years WHERE label = ?', [label]);
  if (f) return f;
  return db.get('SELECT * FROM financial_years ORDER BY start_date DESC LIMIT 1');
}

function getFY(id) {
  return db.get('SELECT * FROM financial_years WHERE id = ?', [id]);
}

function getFYByUid(uid) {
  return db.get('SELECT * FROM financial_years WHERE uid = ?', [uid]);
}

function listFY() {
  return db.all('SELECT * FROM financial_years ORDER BY start_date DESC');
}

/** Resolve the FY a date falls in. */
function resolveFYForDate(dateISO) {
  const label = dates.fyLabel(dateISO);
  const f = db.get('SELECT * FROM financial_years WHERE label = ?', [label]);
  if (f) return f;
  const parsed = dates.parseFY(label);
  const r = db.run(
    'INSERT INTO financial_years (uid, label, start_year, start_date, end_date, status, is_current) VALUES (?,?,?,?,?,?,0)',
    [uuid(), label, parsed.startYear, parsed.start, parsed.end, 'open']
  );
  return db.get('SELECT * FROM financial_years WHERE id = ?', [r.lastInsertRowid]);
}

function validateFYPair(startDate, endDate) {
  if (!dates.inFY(startDate, { start: startDate, end: endDate })) {
    // start must be 01-Apr and end 31-Mar of next year
  }
  const s = dates.parseDate(startDate);
  const e = dates.parseDate(endDate);
  if (!s || !e) throw validation('Invalid financial year dates');
  if (s.getMonth() !== 3 || s.getDate() !== 1) {
    throw validation('Financial year must start on 01 April');
  }
  if (e.getMonth() !== 2 || e.getDate() !== 31) {
    throw validation('Financial year must end on 31 March');
  }
  if (e.getFullYear() !== s.getFullYear() + 1) {
    throw validation('Financial year must span exactly one year (01-Apr to 31-Mar next year)');
  }
  return true;
}

function createFY({ label, startDate, endDate }) {
  if (!label) label = `${dates.fyStartYear(new Date(`${startDate}T00:00:00`))}-${String(dates.fyStartYear(new Date(`${startDate}T00:00:00`)) + 1).slice(-2)}`;
  validateFYPair(startDate, endDate);
  const dup = db.get('SELECT id FROM financial_years WHERE label = ?', [label]);
  if (dup) throw conflict('Financial year already exists');
  const r = db.run(
    'INSERT INTO financial_years (uid, label, start_year, start_date, end_date, status, is_current) VALUES (?,?,?,?,?,?,0)',
    [uuid(), label, dates.parseFY(label).startYear, startDate, endDate, 'open']
  );
  return getFY(r.lastInsertRowid);
}

function setCurrentFY(id) {
  const f = getFY(id);
  if (!f) throw notFound('Financial year not found');
  db.run('UPDATE financial_years SET is_current = 0');
  db.run('UPDATE financial_years SET is_current = 1 WHERE id = ?', [id]);
  return getFY(id);
}

function openFY(id) {
  const f = getFY(id);
  if (!f) throw notFound('Financial year not found');
  if (f.status !== 'closed') throw badRequest('Financial year is not closed');
  db.run('UPDATE financial_years SET status = ? WHERE id = ?', ['open', id]);
  return getFY(id);
}

function closeFY(id) {
  const f = getFY(id);
  if (!f) throw notFound('Financial year not found');
  if (f.status === 'closed') throw badRequest('Financial year already closed');
  // Block closing if there are open transactions.
  const openCount =
    db.get("SELECT COUNT(*) c FROM tenders WHERE fy_id = ? AND status NOT IN ('closed','cancelled')", [id]).c +
    db.get("SELECT COUNT(*) c FROM bills WHERE fy_id = ? AND status NOT IN ('paid','rejected','returned')", [id]).c;
  if (openCount > 0) {
    throw conflict('Cannot close financial year while open tenders or pending bills exist', { openCount });
  }
  db.run('UPDATE financial_years SET status = ?, is_current = 0 WHERE id = ?', ['closed', id]);
  return getFY(id);
}

/** Year-wise serial number allocation (atomic). */
function nextSerial(scope, { fyId, panchayatId = null, prefix = '' }) {
  return db.tx(() => {
    const existing = db.get(
      'SELECT * FROM numbering_sequences WHERE scope = ? AND fy_id = ? AND panchayat_id IS ? AND prefix = ?',
      [scope, fyId, panchayatId, prefix]
    );
    let next;
    if (existing) {
      next = existing.last_value + 1;
      db.run('UPDATE numbering_sequences SET last_value = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [next, existing.id]);
    } else {
      next = 1;
      db.run(
        'INSERT INTO numbering_sequences (scope, fy_id, panchayat_id, prefix, last_value) VALUES (?,?,?,?,1)',
        [scope, fyId, panchayatId, prefix]
      );
    }
    return next;
  });
}

function serialLabel(n, width = 3) {
  return String(n).padStart(width, '0');
}

module.exports = {
  getCurrentFY,
  getFY,
  getFYByUid,
  listFY,
  resolveFYForDate,
  createFY,
  setCurrentFY,
  openFY,
  closeFY,
  nextSerial,
  serialLabel,
};
