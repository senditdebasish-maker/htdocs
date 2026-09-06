'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const money = require('../util/money');
const dates = require('../util/dates');
const { validation, notFound, conflict } = require('../util/errors');
const audit = require('./auditService');
const notifications = require('./notificationService');
const numbering = require('./numberingService');
const documentGen = require('./documentGeneration');

function getAward(id) {
  const a = db.get('SELECT * FROM awards WHERE id = ?', [id]);
  if (!a) throw notFound('Award not found');
  return a;
}

function getAwardForTender(tenderId) {
  return db.get('SELECT * FROM awards WHERE tender_id = ? ORDER BY id DESC LIMIT 1', [tenderId]);
}

/** Recommend award (from L1 or a selected qualified bidder). */
function recommendAward(tenderId, { contractorId, awardedAmountMinor, remarks = null }, actor) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) throw notFound('Tender not found');
  if (t.status !== 'financial_evaluation') throw conflict('Financial evaluation must be complete before award recommendation');
  const b = db.get('SELECT * FROM tender_bidders WHERE tender_id = ? AND contractor_id = ?', [tenderId, contractorId]);
  if (!b) throw conflict('Contractor is not a recorded bidder for this tender');
  if (!['technically_qualified', 'financial_opened', 'awarded'].includes(b.bid_status)) throw conflict('Bidder is not technically qualified');

  const amt = Math.round(Number(awardedAmountMinor) || 0);
  const ceiling = t.tech_sanction_amount_minor || t.estimated_cost_minor;
  if (ceiling && amt > ceiling) {
    throw conflict('Award amount exceeds the approved/estimated amount');
  }
  const existing = getAwardForTender(tenderId);
  if (existing && ['approved', 'loa_issued', 'agreement_done', 'work_order_issued', 'completed'].includes(existing.status)) {
    throw conflict('An award already exists for this tender');
  }
  let awardId;
  if (existing && existing.status === 'recommended') {
    db.run('UPDATE awards SET contractor_id = ?, awarded_amount_minor = ?, remarks = ? WHERE id = ?', [contractorId, amt, remarks, existing.id]);
    awardId = existing.id;
  } else {
    const r = db.run(
      `INSERT INTO awards (uid, tender_id, contractor_id, bidder_id, awarded_amount_minor, rank, status, remarks, created_by)
       VALUES (?,?,?,?,?,?,?,?,?)`,
      [uuid(), tenderId, contractorId, b.id, amt, b.rank || null, 'recommended', remarks || null, actor ? actor.id : null]
    );
    awardId = r.lastInsertRowid;
  }
  audit.record({ actor, action: 'award.recommend', entityType: 'tender', entityId: tenderId, entityLabel: t.tender_number, newValue: { contractorId, amt } });
  notifications.notify({ roleCodes: ['pradhan'], type: 'award_recommended', title: 'Award recommended', body: `${t.tender_number}: award recommendation awaiting approval.`, entityType: 'tender', entityId: tenderId });
  return getAward(awardId);
}

/** Approve an award. */
function approveAward(awardId, { authority = null, date = null }, actor) {
  const a = getAward(awardId);
  if (a.status !== 'recommended') throw conflict('Award is not in recommended state');
  db.run(
    "UPDATE awards SET status = 'approved', approval_authority = ?, approval_date = ? WHERE id = ?",
    [authority || (actor ? actor.name : null), date || dates.todayISO(), awardId]
  );
  db.run("UPDATE tenders SET status = 'awarded' WHERE id = ?", [a.tender_id]);
  db.run("UPDATE tender_bidders SET bid_status = 'awarded' WHERE id = ?", [a.bidder_id]);
  audit.record({ actor, action: 'award.approve', entityType: 'award', entityId: awardId, entityLabel: a.loa_number || `award#${awardId}` });
  return getAward(awardId);
}

/** Issue LOA (generates a PDF). */
async function issueLOA(awardId, actor) {
  const a = getAward(awardId);
  if (a.status !== 'approved') throw conflict('Award must be approved before LOA issuance');
  const fy = db.get('SELECT fy_id FROM tenders WHERE id = ?', [a.tender_id]);
  const loaNumber = numbering.nextNumber('loa', fy ? fy.fy_id : null, { pattern: numbering.setting('numbering.loa.pattern', 'LOA/{FY}/{NNN}') });
  const builder = documentGen.buildLOA(awardId);
  const pdfService = require('./pdfService');
  const result = await pdfService.renderPdf(builder, `LOA-${loaNumber.replace(/[^\w-]/g, '_')}`, {
    docType: 'loa', entityType: 'award', entityId: awardId, generatedBy: actor ? actor.id : null,
  });
  db.run("UPDATE awards SET loa_number = ?, loa_date = ?, status = 'loa_issued' WHERE id = ?", [loaNumber, dates.todayISO(), awardId]);
  audit.record({ actor, action: 'award.loa', entityType: 'award', entityId: awardId, entityLabel: loaNumber });
  return { ...result, award: getAward(awardId) };
}

/** Create an agreement. */
function createAgreement(awardId, { amountMinor = null, completionPeriodDays = null, conditions = null, securityDepositMinor = null, executionDate = null }, actor) {
  const a = getAward(awardId);
  if (!['approved', 'loa_issued'].includes(a.status)) throw conflict('Award must be approved/LOA issued before agreement');
  const fy = db.get('SELECT fy_id FROM tenders WHERE id = ?', [a.tender_id]);
  const agreementNumber = numbering.nextAgreementNumber(fy ? fy.fy_id : null);
  const r = db.run(
    `INSERT INTO agreements (uid, tender_id, award_id, agreement_number, contractor_id, amount_minor, completion_period_days, conditions, security_deposit_minor, execution_date, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), a.tender_id, awardId, agreementNumber, a.contractor_id,
      Math.round(Number(amountMinor ?? a.awarded_amount_minor) || 0), completionPeriodDays || null,
      conditions || null, Math.round(Number(securityDepositMinor ?? a.security_deposit_minor) || 0),
      executionDate || dates.todayISO(), actor ? actor.id : null,
    ]
  );
  db.run("UPDATE awards SET agreement_id = ?, status = 'agreement_done' WHERE id = ?", [r.lastInsertRowid, awardId]);
  audit.record({ actor, action: 'agreement.create', entityType: 'agreement', entityId: r.lastInsertRowid, entityLabel: agreementNumber });
  return db.get('SELECT * FROM agreements WHERE id = ?', [r.lastInsertRowid]);
}

/** Issue a work order. Links tender→agreement→project. */
function issueWorkOrder(awardId, { startDate = null, completionDate = null, conditions = null }, actor) {
  const a = getAward(awardId);
  if (a.status !== 'agreement_done') throw conflict('Agreement must be executed before work order');
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [a.tender_id]);
  const ag = db.get('SELECT * FROM agreements WHERE id = ?', [a.agreement_id]);
  const fy = db.get('SELECT * FROM financial_years WHERE id = ?', [t.fy_id]);
  const woNumber = numbering.nextWorkOrderNumber(t.fy_id);
  const r = db.run(
    `INSERT INTO work_orders (uid, tender_id, award_id, agreement_id, project_id, contractor_id, work_order_number, amount_minor, start_date, completion_date, conditions, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), a.tender_id, awardId, a.agreement_id, t.project_id, a.contractor_id, woNumber,
      ag ? ag.amount_minor : a.awarded_amount_minor,
      startDate || dates.todayISO(), completionDate || null, conditions || null, actor ? actor.id : null,
    ]
  );
  db.run("UPDATE awards SET work_order_id = ?, status = 'work_order_issued' WHERE id = ?", [r.lastInsertRowid, awardId]);
  if (t.project_id) {
    db.run(
      `UPDATE projects SET contractor_id = ?, work_order_id = ?, awarded_amount_minor = ?, status = 'awarded', start_date = COALESCE(start_date, ?), planned_completion_date = COALESCE(planned_completion_date, ?) WHERE id = ?`,
      [a.contractor_id, r.lastInsertRowid, ag ? ag.amount_minor : a.awarded_amount_minor, startDate || dates.todayISO(), completionDate, t.project_id]
    );
  }
  audit.record({ actor, action: 'work_order.issue', entityType: 'work_order', entityId: r.lastInsertRowid, entityLabel: woNumber });
  notifications.notify({ roleCodes: ['technical_officer'], type: 'work_order', title: 'Work order issued', body: `${woNumber} issued for ${t.work_name || t.title}.`, entityType: 'tender', entityId: a.tender_id });
  return db.get('SELECT * FROM work_orders WHERE id = ?', [r.lastInsertRowid]);
}

module.exports = { getAward, getAwardForTender, recommendAward, approveAward, issueLOA, createAgreement, issueWorkOrder };
