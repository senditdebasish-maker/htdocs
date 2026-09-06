'use strict';

const db = require('../db/database');
const fyService = require('./fyService');
const { conflict } = require('../util/errors');

/**
 * Centralized, configurable numbering service.
 *
 * Patterns are stored in `settings` and can be changed by an administrator
 * without code changes. All numbers are unique at the database level and are
 * allocated atomically inside a transaction.
 */

function setting(key, def) {
  const r = db.get('SELECT value FROM settings WHERE key = ?', [key]);
  return r && r.value !== null && r.value !== '' ? r.value : def;
}

function fyLabel(fyId) {
  const f = fyService.getFY(fyId);
  return f ? f.label : '????';
}

/** Build a number from a pattern with placeholders {PREFIX}, {FY}, {NNN}, {N}. */
function render(pattern, fyId, serial) {
  const n3 = String(serial).padStart(3, '0');
  return pattern
    .replace('{PREFIX}', setting('numbering.prefix', 'GP'))
    .replace('{FY}', fyLabel(fyId))
    .replace('{NNN}', n3)
    .replace('{N}', String(serial));
}

function nextNumber(scope, fyId, opts = {}) {
  const prefix = opts.prefix || '';
  const serial = fyService.nextSerial(scope, { fyId, panchayatId: opts.panchayatId || null, prefix });
  return render(opts.pattern || setting(`numbering.${scope}.pattern`, '{PREFIX}/NIT/{FY}/{NNN}'), fyId, serial);
}

/**
 * Allocate a tender number. Guarantees uniqueness by retrying on collision
 * (the UNIQUE constraint on tenders.tender_number is the final arbiter).
 */
function nextTenderNumber(fyId, opts = {}) {
  for (let attempt = 0; attempt < 10; attempt++) {
    const number = nextNumber('tender_nit', fyId, {
      prefix: opts.tenderType || '',
      pattern: setting('numbering.tender_nit.pattern', '{PREFIX}/NIT/{FY}/{NNN}'),
    });
    const exists = db.get('SELECT id FROM tenders WHERE tender_number = ?', [number]);
    if (!exists) return number;
  }
  throw conflict('Could not allocate a unique tender number');
}

function nextBillNumber(fyId) {
  return nextNumber('bill', fyId, { pattern: setting('numbering.bill.pattern', 'BILL/{FY}/{NNN}') });
}

function nextContractorCode() {
  const r = db.get('SELECT MAX(id) m FROM contractors');
  const n = (r && r.m ? r.m : 0) + 1;
  return `CT/${String(n).padStart(4, '0')}`;
}

function nextWorkOrderNumber(fyId) {
  return nextNumber('work_order', fyId, { pattern: setting('numbering.work_order.pattern', 'WO/{FY}/{NNN}') });
}

function nextAgreementNumber(fyId) {
  return nextNumber('agreement', fyId, { pattern: setting('numbering.agreement.pattern', 'AGR/{FY}/{NNN}') });
}

function nextMeasurementNumber(projectId) {
  const r = db.get('SELECT COUNT(*) c FROM measurements WHERE project_id = ?', [projectId]);
  return `MB-${String((r ? r.c : 0) + 1).padStart(3, '0')}`;
}

function nextCorrigendumNumber(tenderId) {
  const r = db.get('SELECT COUNT(*) c FROM corrigenda WHERE tender_id = ?', [tenderId]);
  return `CORR-${(r ? r.c : 0) + 1}`;
}

module.exports = {
  setting,
  nextNumber,
  nextTenderNumber,
  nextBillNumber,
  nextContractorCode,
  nextWorkOrderNumber,
  nextAgreementNumber,
  nextMeasurementNumber,
  nextCorrigendumNumber,
};
