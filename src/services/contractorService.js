'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { validation, notFound, conflict } = require('../util/errors');
const dates = require('../util/dates');
const numbering = require('./numberingService');
const audit = require('./auditService');

const WARNING_DAYS = () => {
  const s = db.get("SELECT value FROM settings WHERE key = 'contractor.expiry_warning_days'");
  return s && s.value ? parseInt(s.value, 10) : 60;
};

/** Compute document expiry status from expiry date. */
function expiryStatus(expiryDate) {
  if (!expiryDate) return 'verification_required';
  const days = dates.daysBetween(dates.todayISO(), expiryDate);
  if (days === null) return 'verification_required';
  if (days < 0) return 'expired';
  if (days <= WARNING_DAYS()) return 'expiring_soon';
  return 'valid';
}

function getContractor(id) {
  const c = db.get('SELECT * FROM contractors WHERE id = ?', [id]);
  if (!c) throw notFound('Contractor not found');
  return c;
}

function listContractors({ q, status, limit = 50, offset = 0 } = {}) {
  const conds = ['c.deleted_at IS NULL'];
  const params = [];
  if (q) { conds.push('(c.legal_name LIKE ? OR c.business_name LIKE ? OR c.contractor_code LIKE ? OR c.pan LIKE ?)'); params.push(`%${q}%`, `%${q}%`, `%${q}%`, `%${q}%`); }
  if (status) { conds.push('c.status = ?'); params.push(status); }
  const where = conds.join(' AND ');
  const rows = db.all(
    `SELECT c.*,
       (SELECT COUNT(*) FROM contractor_documents cd WHERE cd.contractor_id = c.id AND cd.expiry_status = 'expired') AS expired_docs,
       (SELECT COUNT(*) FROM contractor_documents cd WHERE cd.contractor_id = c.id AND cd.expiry_status = 'expiring_soon') AS expiring_docs
       FROM contractors c WHERE ${where} ORDER BY c.id DESC LIMIT ? OFFSET ?`,
    [...params, limit, offset]
  );
  const total = db.get(`SELECT COUNT(*) c FROM contractors c WHERE ${where}`, params).c;
  return { rows, total };
}

function createContractor(data, actor) {
  if (!data.legal_name) throw validation('Legal name is required');
  const code = numbering.nextContractorCode();
  const r = db.run(
    `INSERT INTO contractors
      (uid, contractor_code, legal_name, business_name, address, mobile, email, registration_no, registration_class,
       registration_valid_from, registration_valid_to, pan, gst, bank_name, bank_account_no, bank_ifsc,
       experience_summary, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), code, data.legal_name, data.business_name || null, data.address || null, data.mobile || null,
      data.email || null, data.registration_no || null, data.registration_class || null,
      data.registration_valid_from || null, data.registration_valid_to || null, data.pan || null, data.gst || null,
      data.bank_name || null, data.bank_account_no || null, data.bank_ifsc || null,
      data.experience_summary || null, data.status || 'active', actor ? actor.id : null,
    ]
  );
  audit.record({ actor, action: 'contractor.create', entityType: 'contractor', entityId: r.lastInsertRowid, entityLabel: data.legal_name });
  return getContractor(r.lastInsertRowid);
}

function updateContractor(id, fields, actor) {
  const c = getContractor(id);
  const allowed = new Set([
    'legal_name', 'business_name', 'address', 'mobile', 'email', 'registration_no', 'registration_class',
    'registration_valid_from', 'registration_valid_to', 'pan', 'gst', 'bank_name', 'bank_account_no', 'bank_ifsc',
    'experience_summary', 'status', 'is_debarred', 'debarment_reason', 'debarment_from', 'debarment_to',
    'performance_rating', 'performance_remarks',
  ]);
  const updates = [];
  const params = [];
  for (const [k, v] of Object.entries(fields)) {
    if (!allowed.has(k)) continue;
    updates.push(`${k} = ?`);
    params.push(v === '' ? null : v);
  }
  if (!updates.length) return c;
  params.push(id);
  db.run(`UPDATE contractors SET ${updates.join(', ')} WHERE id = ?`, params);
  audit.record({ actor, action: 'contractor.update', entityType: 'contractor', entityId: id, entityLabel: c.legal_name });
  return getContractor(id);
}

/** Add/update a contractor document and compute its expiry status. */
function upsertContractorDocument(contractorId, { docType, docName, expiryDate, issuedDate, remarks }, actor) {
  getContractor(contractorId);
  const status = expiryStatus(expiryDate);
  const r = db.run(
    `INSERT INTO contractor_documents (uid, contractor_id, doc_type, doc_name, issued_date, expiry_date, expiry_status, remarks)
     VALUES (?,?,?,?,?,?,?,?)`,
    [uuid(), contractorId, docType, docName, issuedDate || null, expiryDate || null, status, remarks || null]
  );
  audit.record({ actor, action: 'contractor.document.add', entityType: 'contractor', entityId: contractorId, entityLabel: docName });
  return db.get('SELECT * FROM contractor_documents WHERE id = ?', [r.lastInsertRowid]);
}

/** Refresh expiry statuses for all contractor documents. */
function refreshExpiryStatuses() {
  const docs = db.all('SELECT * FROM contractor_documents');
  for (const d of docs) {
    db.run('UPDATE contractor_documents SET expiry_status = ? WHERE id = ?', [expiryStatus(d.expiry_date), d.id]);
  }
  return docs.length;
}

module.exports = { getContractor, listContractors, createContractor, updateContractor, upsertContractorDocument, refreshExpiryStatuses, expiryStatus };
