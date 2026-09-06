'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { json } = require('../db/database');
const money = require('../util/money');
const dates = require('../util/dates');
const { validation, notFound, conflict, forbidden } = require('../util/errors');
const audit = require('./auditService');
const notifications = require('./notificationService');
const financialService = require('./financialService');
const contractorService = require('./contractorService');

function getBidder(id) {
  const b = db.get('SELECT * FROM tender_bidders WHERE id = ?', [id]);
  if (!b) throw notFound('Bidder not found');
  return b;
}

function listBidders(tenderId) {
  return db.all(
    `SELECT b.*, c.legal_name, c.business_name, c.contractor_code, c.registration_class
       FROM tender_bidders b JOIN contractors c ON c.id = b.contractor_id
      WHERE b.tender_id = ? ORDER BY b.id`,
    [tenderId]
  );
}

/** Record a bidder for a tender. */
function addBidder(tenderId, { contractorId, bidderLabel = null, submissionTime = null, emdPaidMinor = null, emdDetails = null }, actor) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) throw notFound('Tender not found');
  if (!['bidding', 'bid_closed', 'technical_evaluation'].includes(t.status)) {
    throw conflict('Bidders can only be recorded once bidding has started');
  }
  const c = contractorService.getContractor(contractorId);
  if (c.status === 'debarred') throw conflict('Contractor is debarred and cannot bid');
  const existing = db.get('SELECT id FROM tender_bidders WHERE tender_id = ? AND contractor_id = ?', [tenderId, contractorId]);
  if (existing) throw conflict('Contractor is already recorded as a bidder');
  const r = db.run(
    `INSERT INTO tender_bidders (uid, tender_id, contractor_id, bidder_label, submission_time, emd_paid_minor, emd_details, created_by)
     VALUES (?,?,?,?,?,?,?,?)`,
    [uuid(), tenderId, contractorId, bidderLabel || c.legal_name, submissionTime || dates.nowISO(), emdPaidMinor || null, emdDetails || null, actor ? actor.id : null]
  );
  audit.record({ actor, action: 'bid.record', entityType: 'tender_bidder', entityId: r.lastInsertRowid, entityLabel: c.legal_name });
  return getBidder(r.lastInsertRowid);
}

/** Record technical bid opening. */
function recordTechnicalOpening(tenderId, { openingDate, attendees, observations }, actor) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) throw notFound('Tender not found');
  if (t.status !== 'bid_closed') throw conflict('Bids must be closed before technical opening');
  const r = db.run(
    `INSERT INTO technical_openings (uid, tender_id, opening_date, opened_by, attendees, observations)
     VALUES (?,?,?,?,?,?)`,
    [uuid(), tenderId, openingDate || dates.todayISO(), actor ? actor.id : null, json(attendees || []), observations || null]
  );
  db.run("UPDATE tenders SET status = 'technical_evaluation' WHERE id = ?", [tenderId]);
  db.run("UPDATE tender_bidders SET bid_status = 'technical_opened' WHERE tender_id = ? AND bid_status = 'submitted'", [tenderId]);
  audit.record({ actor, action: 'bid.technical_open', entityType: 'tender', entityId: tenderId, entityLabel: t.tender_number });
  return db.get('SELECT * FROM technical_openings WHERE id = ?', [r.lastInsertRowid]);
}

// ---- Technical evaluation ----

function listCriteria(tenderId) {
  return db.all('SELECT * FROM evaluation_criteria WHERE tender_id = ? ORDER BY sort_order, id', [tenderId]);
}

function addCriterion(tenderId, { code, criterion, requirement, isRequired = true }, actor) {
  const r = db.run(
    `INSERT INTO evaluation_criteria (uid, tender_id, code, criterion, requirement, is_required, sort_order)
     VALUES (?,?,?,?,?,?,?)`,
    [uuid(), tenderId, code || `C${Date.now()}`, criterion, requirement || null, isRequired ? 1 : 0, db.get('SELECT COALESCE(MAX(sort_order),0) m FROM evaluation_criteria WHERE tender_id = ?', [tenderId]).m + 1]
  );
  return db.get('SELECT * FROM evaluation_criteria WHERE id = ?', [r.lastInsertRowid]);
}

function setEvaluation(tenderId, bidderId, criterionId, { result, bidderResponse, remarks }, actor) {
  const existing = db.get('SELECT id FROM technical_evaluations WHERE bidder_id = ? AND criterion_id = ?', [bidderId, criterionId]);
  if (existing) {
    db.run(
      `UPDATE technical_evaluations SET result = ?, bidder_response = ?, remarks = ?, evaluated_by = ?, evaluated_at = CURRENT_TIMESTAMP WHERE id = ?`,
      [result, bidderResponse || null, remarks || null, actor ? actor.id : null, existing.id]
    );
    return db.get('SELECT * FROM technical_evaluations WHERE id = ?', [existing.id]);
  }
  const r = db.run(
    `INSERT INTO technical_evaluations (uid, tender_id, bidder_id, criterion_id, result, bidder_response, remarks, evaluated_by)
     VALUES (?,?,?,?,?,?,?,?)`,
    [uuid(), tenderId, bidderId, criterionId, result, bidderResponse || null, remarks || null, actor ? actor.id : null]
  );
  return db.get('SELECT * FROM technical_evaluations WHERE id = ?', [r.lastInsertRowid]);
}

function evaluationsForBidder(bidderId) {
  return db.all('SELECT * FROM technical_evaluations WHERE bidder_id = ?', [bidderId]);
}

/**
 * Finalize technical evaluation: every rejection must have a reason.
 * Computes bidder qualification from criteria results.
 */
function finalizeTechnicalEvaluation(tenderId, { rejectionReasons = {} }, actor) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) throw notFound('Tender not found');
  if (t.status !== 'technical_evaluation') throw conflict('Tender is not in technical evaluation');
  const bidders = listBidders(tenderId);
  const criteria = listCriteria(tenderId);
  if (criteria.length === 0) throw conflict('No evaluation criteria configured');

  for (const b of bidders) {
    const evals = evaluationsForBidder(b.id);
    const fail = evals.filter((e) => e.result === 'fail');
    const verification = evals.filter((e) => e.result === 'verification_required');
    let status = 'technically_qualified';
    let reason = null;
    if (verification.length) {
      status = 'clarification_required';
      reason = 'Verification required on one or more criteria';
    } else if (fail.length) {
      status = 'technically_disqualified';
      reason = rejectionReasons[b.id] || `Failed ${fail.length} criterion/criteria`;
      if (!rejectionReasons[b.id]) throw conflict(`Rejection reason required for bidder ${b.legal_name}`);
    }
    db.run('UPDATE tender_bidders SET bid_status = ?, rejection_reason = ? WHERE id = ?', [status, reason, b.id]);
  }
  db.run("UPDATE tenders SET status = 'financial_evaluation' WHERE id = ?", [tenderId]);
  audit.record({ actor, action: 'evaluation.technical_finalize', entityType: 'tender', entityId: tenderId, entityLabel: t.tender_number });
  return { bidders: listBidders(tenderId), criteria };
}

// ---- Financial evaluation ----

/** Record a financial bid (confidential). Only for qualified bidders. Money in MINOR. */
function recordFinancialBid(tenderId, bidderId, { totalAmountMinor, baseAmountMinor = null, taxAmountMinor = null, discountMinor = null, items = [] }, actor) {
  const b = getBidder(bidderId);
  if (b.tender_id !== tenderId) throw validation('Bidder does not belong to this tender');
  if (b.bid_status !== 'technically_qualified') throw conflict('Only technically qualified bidders can have financial bids opened');
  const total = Math.round(Number(totalAmountMinor) || 0);
  const base = Math.round(Number(baseAmountMinor) || 0);
  const tax = Math.round(Number(taxAmountMinor) || 0);
  const disc = Math.round(Number(discountMinor) || 0);
  const existing = db.get('SELECT id FROM financial_bids WHERE tender_id = ? AND bidder_id = ?', [tenderId, bidderId]);
  let fbId;
  if (existing) {
    db.run(
      'UPDATE financial_bids SET total_amount_minor = ?, base_amount_minor = ?, tax_amount_minor = ?, discount_minor = ?, quoted_on = CURRENT_TIMESTAMP WHERE id = ?',
      [total, base, tax, disc, existing.id]
    );
    fbId = existing.id;
    db.run('DELETE FROM financial_bid_items WHERE financial_bid_id = ?', [fbId]);
  } else {
    const r = db.run(
      `INSERT INTO financial_bids (uid, tender_id, bidder_id, total_amount_minor, base_amount_minor, tax_amount_minor, discount_minor, created_by)
       VALUES (?,?,?,?,?,?,?,?)`,
      [uuid(), tenderId, bidderId, total, base, tax, disc, actor ? actor.id : null]
    );
    fbId = r.lastInsertRowid;
  }
  for (const it of items) {
    db.run(
      `INSERT INTO financial_bid_items (uid, financial_bid_id, boq_item_id, item_no, bidder_rate_minor, quantity, amount_minor)
       VALUES (?,?,?,?,?,?,?)`,
      [uuid(), fbId, it.boq_item_id || null, it.item_no || null, Math.round(Number(it.bidder_rate_minor) || 0), it.quantity || 0, Math.round(Number(it.amount_minor) || 0)]
    );
  }
  db.run("UPDATE tender_bidders SET bid_status = 'financial_opened' WHERE id = ?", [bidderId]);
  audit.record({ actor, action: 'evaluation.financial_record', entityType: 'tender_bidder', entityId: bidderId, entityLabel: `${b.bidder_label} financial bid` });
  return db.get('SELECT * FROM financial_bids WHERE id = ?', [fbId]);
}

/** Compute L1/L2/L3 rankings. */
function computeRankings(tenderId, actor) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) throw notFound('Tender not found');
  const ranked = financialService.rankFinancialBids(tenderId);
  audit.record({ actor, action: 'evaluation.rank', entityType: 'tender', entityId: tenderId, entityLabel: t.tender_number, newValue: json(ranked) });
  return ranked;
}

/** Comparative data (public-safe: no confidential per-item financials). */
function comparativeStatement(tenderId) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) throw notFound('Tender not found');
  const bidders = listBidders(tenderId);
  const fin = db.all('SELECT * FROM financial_bids WHERE tender_id = ?', [tenderId]);
  const finByBidder = {};
  for (const f of fin) finByBidder[f.bidder_id] = f;
  const rows = bidders.map((b) => {
    const f = finByBidder[b.id];
    const total = f ? f.total_amount_minor : null;
    const variation = total !== null && t.estimated_cost_minor ? money.pctDiff(t.estimated_cost_minor, total) : null;
    return {
      bidderId: b.id,
      bidder: b.legal_name || b.business_name || b.bidder_label,
      qualification: b.bid_status,
      rank: b.rank || null,
      totalMinor: total,
      totalFmt: total !== null ? money.formatMinorINR(total) : '—',
      variation: variation !== null ? Number(variation.toFixed(2)) : null,
      rejectionReason: b.rejection_reason,
    };
  });
  return { tender: t, estimatedMinor: t.estimated_cost_minor, rows };
}

module.exports = {
  getBidder,
  listBidders,
  addBidder,
  recordTechnicalOpening,
  listCriteria,
  addCriterion,
  setEvaluation,
  evaluationsForBidder,
  finalizeTechnicalEvaluation,
  recordFinancialBid,
  computeRankings,
  comparativeStatement,
};
