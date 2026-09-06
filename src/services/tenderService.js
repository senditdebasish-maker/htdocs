'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { json, parseJson } = require('../db/database');
const money = require('../util/money');
const dates = require('../util/dates');
const { validation, notFound, conflict, forbidden, badRequest } = require('../util/errors');
const numbering = require('./numberingService');
const compliance = require('./complianceService');
const workflow = require('./workflowService');
const audit = require('./auditService');
const notifications = require('./notificationService');
const documentGen = require('./documentGeneration');

const STATUS = [
  'draft', 'under_approval', 'approved', 'nit_generated', 'published', 'bidding', 'bid_closed',
  'technical_evaluation', 'financial_evaluation', 'awarded', 'cancelled', 'retendered', 'closed',
];

const EDITABLE = new Set(['draft']);

function getTender(id) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [id]);
  if (!t) throw notFound('Tender not found');
  return t;
}

function getTenderByUid(uid) {
  const t = db.get('SELECT * FROM tenders WHERE uid = ?', [uid]);
  if (!t) throw notFound('Tender not found');
  return t;
}

function listTenders({ fyId, status, tenderType, schemeId, fundId, q, limit = 50, offset = 0 }) {
  const conds = ['t.deleted_at IS NULL'];
  const params = [];
  if (fyId) { conds.push('t.fy_id = ?'); params.push(fyId); }
  if (status) { conds.push('t.status = ?'); params.push(status); }
  if (tenderType) { conds.push('t.tender_type = ?'); params.push(tenderType); }
  if (schemeId) { conds.push('t.scheme_id = ?'); params.push(schemeId); }
  if (fundId) { conds.push('t.fund_id = ?'); params.push(fundId); }
  if (q) { conds.push('(t.tender_number LIKE ? OR t.title LIKE ? OR t.work_name LIKE ?)'); params.push(`%${q}%`, `%${q}%`, `%${q}%`); }
  const where = conds.join(' AND ');
  const rows = db.all(
    `SELECT t.*, f.label AS fy_label, s.name AS scheme_name, fu.name AS fund_name
       FROM tenders t
       LEFT JOIN financial_years f ON f.id = t.fy_id
       LEFT JOIN schemes s ON s.id = t.scheme_id
       LEFT JOIN funds fu ON fu.id = t.fund_id
      WHERE ${where} ORDER BY t.id DESC LIMIT ? OFFSET ?`,
    [...params, limit, offset]
  );
  const total = db.get(`SELECT COUNT(*) c FROM tenders t WHERE ${where}`, params).c;
  return { rows, total };
}

function validateDates(t) {
  const seq = [
    ['bid_start_date', 'bid_close_date'],
    ['bid_close_date', 'technical_open_date'],
    ['technical_open_date', 'financial_open_date'],
  ];
  for (const [a, b] of seq) {
    if (t[a] && t[b] && dates.isAfter(t[a], t[b])) {
      throw validation(`${a} must not be after ${b}`);
    }
  }
}

/** Create a tender (wizard step 1). Allocates a unique tender number. */
function createTender(data, actor) {
  const fy = db.get('SELECT * FROM financial_years WHERE id = ?', [data.fy_id]);
  if (!fy) throw validation('A financial year must be selected');
  if (fy.status === 'closed') throw conflict('Cannot create a tender in a closed financial year');
  if (!data.procurement_category) throw validation('Procurement category is required');
  if (!data.tender_type) throw validation('Tender type is required');

  const tenderNumber = numbering.nextTenderNumber(fy.id, { tenderType: data.tender_type });
  const r = db.run(
    `INSERT INTO tenders
      (uid, fy_id, project_id, scheme_id, fund_id, ruleset_id, tender_number, tender_type, procurement_category,
       procurement_method, title, description, location, work_name, status, workflow_stage, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), fy.id, data.project_id || null, data.scheme_id || null, data.fund_id || null,
      data.ruleset_id || null, tenderNumber, data.tender_type, data.procurement_category,
      data.procurement_method || null, data.title || data.work_name || 'Untitled tender',
      data.description || null, data.location || null, data.work_name || data.title || null,
      'draft', 'draft', actor ? actor.id : null,
    ]
  );
  const tender = getTender(r.lastInsertRowid);
  snapshotVersion(tender.id, 1, 'create', 'Tender created', tender, actor);
  audit.record({ actor, action: 'tender.create', entityType: 'tender', entityId: tender.id, entityLabel: tenderNumber });
  return tender;
}

/** Update editable fields. Only allowed in draft. */
function updateTender(id, fields, actor) {
  const t = getTender(id);
  if (!EDITABLE.has(t.status)) throw conflict(`Tender is in "${t.status}" status and is locked. Use the corrigendum process for changes.`);
  const allowed = new Set([
    'project_id', 'scheme_id', 'fund_id', 'ruleset_id', 'tender_type', 'procurement_category', 'procurement_method',
    'title', 'description', 'location', 'work_name',
    'admin_approval_no', 'admin_approval_date', 'admin_approval_authority', 'admin_approval_amount_minor',
    'tech_sanction_no', 'tech_sanction_date', 'tech_sanction_authority', 'tech_sanction_amount_minor',
    'estimated_cost_minor', 'tender_value_minor', 'emd_minor', 'emd_exemption_notes', 'tender_fee_minor',
    'security_deposit_minor', 'security_deposit_pct', 'tax_config', 'budget_provision', 'head_of_account',
    'completion_period_days', 'technical_specification', 'eligibility_notes',
    'general_conditions', 'special_conditions', 'payment_conditions', 'completion_conditions',
    'extension_conditions', 'penalty_provisions', 'defect_liability',
    'publication_date', 'bid_start_date', 'bid_close_date', 'technical_open_date', 'financial_open_date', 'bid_validity_days',
  ]);
  const updates = [];
  const params = [];
  for (const [k, v] of Object.entries(fields)) {
    if (!allowed.has(k)) continue;
    updates.push(`${k} = ?`);
    params.push(v === '' ? null : v);
  }
  if (!updates.length) return t;
  params.push(id);
  db.run(`UPDATE tenders SET ${updates.join(', ')} WHERE id = ?`, params);
  const fresh = getTender(id);
  validateDates(fresh);
  snapshotVersion(id, nextVersion(id), 'update', 'Tender fields updated', fresh, actor);
  audit.record({ actor, action: 'tender.update', entityType: 'tender', entityId: id, entityLabel: fresh.tender_number });
  return fresh;
}

function nextVersion(tenderId) {
  const r = db.get('SELECT MAX(version_no) m FROM tender_versions WHERE tender_id = ?', [tenderId]);
  return (r && r.m ? r.m : 0) + 1;
}

function snapshotVersion(tenderId, versionNo, changeType, reason, tender, actor) {
  db.run(
    `INSERT INTO tender_versions (uid, tender_id, version_no, change_type, reason, snapshot, changed_by)
     VALUES (?,?,?,?,?,?,?)`,
    [uuid(), tenderId, versionNo, changeType, reason, json(tender), actor ? actor.id : null]
  );
}

/** Run the compliance engine and return results. */
function runCompliance(id, actor) {
  const t = getTender(id);
  return compliance.evaluateTender(t, { persist: true, actorId: actor ? actor.id : null });
}

/** Submit tender for approval. */
function submitForApproval(id, actor) {
  const t = getTender(id);
  if (t.status !== 'draft') throw conflict('Only draft tenders can be submitted for approval');
  if (!t.admin_approval_no || !t.tech_sanction_no) {
    throw conflict('Administrative approval and technical sanction must be recorded before submission');
  }
  const boq = db.get('SELECT COUNT(*) c FROM boq_items WHERE tender_id = ?', [id]);
  if (boq.c === 0) throw conflict('At least one BOQ item is required before submission');
  db.run("UPDATE tenders SET status = 'under_approval', workflow_stage = 'under_approval' WHERE id = ?", [id]);
  workflow.init('tender', id);
  snapshotVersion(id, nextVersion(id), 'submit', 'Submitted for approval', getTender(id), actor);
  audit.record({ actor, action: 'tender.submit', entityType: 'tender', entityId: id, entityLabel: t.tender_number });
  notifications.notify({ roleCodes: ['technical_officer'], type: 'approval_pending', title: 'Tender submitted for approval', body: `${t.tender_number} is awaiting technical verification.`, entityType: 'tender', entityId: id });
  return getTender(id);
}

/** Act on the current approval step. */
function workflowAction(id, action, remarks, actor) {
  const t = getTender(id);
  if (t.status !== 'under_approval') throw conflict('Tender is not in approval workflow');
  const result = workflow.act('tender', id, {
    action,
    remarks,
    actor,
    onComplete: () => {
      db.run("UPDATE tenders SET status = 'approved', workflow_stage = 'approved' WHERE id = ?", [id]);
      snapshotVersion(id, nextVersion(id), 'approve', 'Tender approved', getTender(id), actor);
      notifications.notify({ roleCodes: ['panchayat_secretary', 'pradhan'], type: 'tender_approved', title: 'Tender approved', body: `${t.tender_number} has been approved and can proceed to NIT generation.`, entityType: 'tender', entityId: id });
    },
  });
  if (['reject', 'return', 'clarification'].includes(action)) {
    // Send back to draft for correction; workflow must be re-run on resubmit.
    db.run("UPDATE tenders SET status = 'draft', workflow_stage = 'draft' WHERE id = ?", [id]);
    snapshotVersion(id, nextVersion(id), action, `Workflow ${action}: ${remarks || ''}`, getTender(id), actor);
    notifications.notify({ roleCodes: ['panchayat_secretary'], type: 'tender_returned', title: 'Tender returned', body: `${t.tender_number} was ${action === 'reject' ? 'rejected' : 'returned'}: ${remarks || ''}`, entityType: 'tender', entityId: id });
  }
  return { ...result, tender: getTender(id) };
}

/** Generate NIT (versioned). Returns generation info + buffer. */
async function generateNIT(id, actor) {
  const t = getTender(id);
  if (!['approved', 'nit_generated'].includes(t.status)) {
    throw conflict('Tender must be approved before NIT generation');
  }
  const builder = documentGen.buildNIT(id);
  const pdfService = require('./pdfService');
  const result = await pdfService.renderPdf(builder, `NIT-${t.tender_number.replace(/[^\w-]/g, '_')}`, {
    docType: 'nit', entityType: 'tender', entityId: id, templateId: null, generatedBy: actor ? actor.id : null,
  });
  const versionNo = db.get('SELECT MAX(version_no) m FROM nit_versions WHERE tender_id = ?', [id]);
  const vn = (versionNo && versionNo.m ? versionNo.m : 0) + 1;
  db.run(
    `INSERT INTO nit_versions (uid, tender_id, version_no, content_json, generated_by, document_id)
     VALUES (?,?,?,?,?,?)`,
    [uuid(), id, vn, json(documentGen.nitMergeData(id)), actor ? actor.id : null, result.document.id]
  );
  db.run("UPDATE tenders SET nit_number = COALESCE(nit_number, tender_number), status = CASE WHEN status = 'approved' THEN 'nit_generated' ELSE status END WHERE id = ?", [id]);
  snapshotVersion(id, nextVersion(id), 'nit_generate', 'NIT generated', getTender(id), actor);
  audit.record({ actor, action: 'nit.generate', entityType: 'tender', entityId: id, entityLabel: t.tender_number });
  return { ...result, tender: getTender(id) };
}

/** Publish / record publication. */
function publish(id, { publicationDate = null, externalRef = null }, actor) {
  const t = getTender(id);
  if (!['approved', 'nit_generated'].includes(t.status)) {
    throw conflict('Tender must be approved (and NIT generated) before publication');
  }
  const pubDate = publicationDate || t.publication_date || dates.todayISO();
  db.run("UPDATE tenders SET status = 'published', publication_date = ?, workflow_stage = 'published' WHERE id = ?", [pubDate, id]);
  if (externalRef && (externalRef.official_portal || externalRef.external_tender_id)) {
    db.run(
      `INSERT INTO external_refs (uid, entity_type, entity_id, official_portal, external_tender_id, external_reference_no, publication_status, official_url, publication_timestamp, sync_method)
       VALUES (?,?,?,?,?,?,?,?,?,?)`,
      [uuid(), 'tender', id, externalRef.official_portal, externalRef.external_tender_id, externalRef.external_reference_no, externalRef.publication_status, externalRef.official_url, externalRef.publication_timestamp, externalRef.sync_method || 'manual']
    );
  }
  snapshotVersion(id, nextVersion(id), 'publish', 'Tender published', getTender(id), actor);
  audit.record({ actor, action: 'tender.publish', entityType: 'tender', entityId: id, entityLabel: t.tender_number });
  return getTender(id);
}

/** Move to bidding. */
function startBidding(id, actor) {
  const t = getTender(id);
  if (t.status !== 'published') throw conflict('Tender must be published before bidding');
  db.run("UPDATE tenders SET status = 'bidding' WHERE id = ?", [id]);
  audit.record({ actor, action: 'tender.start_bidding', entityType: 'tender', entityId: id, entityLabel: t.tender_number });
  return getTender(id);
}

/** Close bidding. */
function closeBids(id, actor) {
  const t = getTender(id);
  if (!['published', 'bidding'].includes(t.status)) throw conflict('Tender is not in bidding');
  db.run("UPDATE tenders SET status = 'bid_closed' WHERE id = ?", [id]);
  audit.record({ actor, action: 'tender.close_bids', entityType: 'tender', entityId: id, entityLabel: t.tender_number });
  return getTender(id);
}

/** Cancel a tender (permanent, reversible only via controlled process). */
function cancelTender(id, { reason, authority = null, cancelDate = null } = {}, actor) {
  const t = getTender(id);
  if (t.status === 'cancelled') throw conflict('Tender is already cancelled');
  if (t.status === 'awarded' || t.status === 'closed') throw conflict('Cannot cancel an awarded/closed tender');
  if (!reason) throw validation('Cancellation reason is required');
  db.run(
    `INSERT INTO tender_cancellations (uid, tender_id, reason, authority, cancel_date, created_by)
     VALUES (?,?,?,?,?,?)`,
    [uuid(), id, reason, authority, cancelDate || dates.todayISO(), actor ? actor.id : null]
  );
  db.run("UPDATE tenders SET status = 'cancelled' WHERE id = ?", [id]);
  // Cancel any pending approval steps.
  db.run("UPDATE approval_steps SET status = 'cancelled' WHERE entity_type = 'tender' AND entity_id = ? AND status IN ('pending','in_progress')", [id]);
  snapshotVersion(id, nextVersion(id), 'cancel', reason, getTender(id), actor);
  audit.record({ actor, action: 'tender.cancel', entityType: 'tender', entityId: id, entityLabel: t.tender_number, reason });
  return getTender(id);
}

/** Create a corrigendum; original data is never overwritten. */
function createCorrigendum(id, { reason, changes, newDates }, actor) {
  const t = getTender(id);
  if (t.status === 'cancelled' || t.status === 'closed') throw conflict('Cannot corrigendum a cancelled/closed tender');
  if (!Array.isArray(changes) || changes.length === 0) throw validation('At least one changed field is required');
  const num = numbering.nextCorrigendumNumber(id);
  const r = db.run(
    `INSERT INTO corrigenda (uid, tender_id, corrigendum_number, reason, changes, new_dates, created_by)
     VALUES (?,?,?,?,?,?,?)`,
    [uuid(), id, num, reason, json(changes), json(newDates || {}), actor ? actor.id : null]
  );
  snapshotVersion(id, nextVersion(id), 'corrigendum', `Corrigendum ${num}: ${reason}`, t, actor);
  audit.record({ actor, action: 'tender.corrigendum', entityType: 'tender', entityId: id, entityLabel: `${t.tender_number} (${num})`, reason });
  notifications.notify({ roleCodes: ['panchayat_secretary', 'technical_officer'], type: 'corrigendum', title: `Corrigendum ${num}`, body: `${t.tender_number}: ${reason}`, entityType: 'tender', entityId: id });
  return db.get('SELECT * FROM corrigenda WHERE id = ?', [r.lastInsertRowid]);
}

/**
 * Create a re-tender. Carries forward only approved reusable data
 * (project/scheme/estimate/BOQ/conditions). NEVER copies bidder/financial data.
 */
function createRetender(id, { reason, carryForward = [] }, actor) {
  const t = getTender(id);
  if (['retendered', 'awarded', 'closed'].includes(t.status)) throw conflict('Cannot re-tender from this status');
  const fy = t.fy_id;
  const tenderNumber = numbering.nextTenderNumber(fy, { tenderType: t.tender_type });
  const r = db.run(
    `INSERT INTO tenders
      (uid, fy_id, project_id, scheme_id, fund_id, ruleset_id, tender_number, tender_type, procurement_category,
       procurement_method, title, description, location, work_name,
       admin_approval_no, admin_approval_date, admin_approval_authority, admin_approval_amount_minor,
       tech_sanction_no, tech_sanction_date, tech_sanction_authority, tech_sanction_amount_minor,
       estimated_cost_minor, tender_value_minor, emd_minor, tender_fee_minor, security_deposit_minor, security_deposit_pct,
       completion_period_days, technical_specification, eligibility_notes,
       general_conditions, special_conditions, payment_conditions, completion_conditions,
       extension_conditions, penalty_provisions, defect_liability, status, workflow_stage, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), t.fy_id, t.project_id, t.scheme_id, t.fund_id, t.ruleset_id, tenderNumber, t.tender_type, t.procurement_category,
      t.procurement_method, `${t.title} (Re-Tender)`, t.description, t.location, t.work_name,
      t.admin_approval_no, t.admin_approval_date, t.admin_approval_authority, t.admin_approval_amount_minor,
      t.tech_sanction_no, t.tech_sanction_date, t.tech_sanction_authority, t.tech_sanction_amount_minor,
      t.estimated_cost_minor, t.tender_value_minor, t.emd_minor, t.tender_fee_minor, t.security_deposit_minor, t.security_deposit_pct,
      t.completion_period_days, t.technical_specification, t.eligibility_notes,
      t.general_conditions, t.special_conditions, t.payment_conditions, t.completion_conditions,
      t.extension_conditions, t.penalty_provisions, t.defect_liability, 'draft', 'draft', actor ? actor.id : null,
    ]
  );
  const newId = r.lastInsertRowid;
  // Copy BOQ (estimate) — never bids.
  const boq = db.all('SELECT * FROM boq_items WHERE tender_id = ?', [id]);
  const insBoq = db.prepare(
    `INSERT INTO boq_items (uid, tender_id, item_no, group_name, description, specification, unit, quantity, estimated_rate_minor, tax_pct, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`
  );
  for (const item of boq) insBoq.run(uuid(), newId, item.item_no, item.group_name, item.description, item.specification, item.unit, item.quantity, item.estimated_rate_minor, item.tax_pct, item.sort_order);

  db.run(
    `INSERT INTO retenders (uid, original_tender_id, new_tender_id, reason, carry_forward, created_by)
     VALUES (?,?,?,?,?,?)`,
    [uuid(), id, newId, reason, json(carryForward), actor ? actor.id : null]
  );
  db.run("UPDATE tenders SET status = 'retendered' WHERE id = ?", [id]);
  snapshotVersion(id, nextVersion(id), 'retender', `Re-tendered to ${tenderNumber}`, t, actor);
  snapshotVersion(newId, 1, 'create', `Created as re-tender of ${t.tender_number}`, getTender(newId), actor);
  audit.record({ actor, action: 'tender.retender', entityType: 'tender', entityId: id, entityLabel: `${t.tender_number} -> ${tenderNumber}`, reason });
  return getTender(newId);
}

module.exports = {
  STATUS,
  getTender,
  getTenderByUid,
  listTenders,
  createTender,
  updateTender,
  runCompliance,
  submitForApproval,
  workflowAction,
  generateNIT,
  publish,
  startBidding,
  closeBids,
  cancelTender,
  createCorrigendum,
  createRetender,
};
