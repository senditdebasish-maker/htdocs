'use strict';

const db = require('../db/database');
const financialService = require('./financialService');

function fyWhere(fyId, alias = 't') {
  return fyId ? ` AND ${alias}.fy_id = ${Number(fyId)}` : '';
}

/** Enterprise dashboard card data. */
function dashboard(fyId) {
  const F = fyWhere(fyId);
  const Fp = fyWhere(fyId, 'p');
  const Fb = fyWhere(fyId, 'b');
  const Fpay = fyWhere(fyId, 'pay');

  const tenderCounts = db.get(
    `SELECT
       COUNT(*) total,
       SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) draft,
       SUM(CASE WHEN status IN ('approved','nit_generated','published') THEN 1 ELSE 0 END) published,
       SUM(CASE WHEN status='bidding' THEN 1 ELSE 0 END) bidding,
       SUM(CASE WHEN status IN ('technical_evaluation','financial_evaluation') THEN 1 ELSE 0 END) evaluation,
       SUM(CASE WHEN status='awarded' THEN 1 ELSE 0 END) awarded,
       SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled,
       SUM(CASE WHEN status='retendered' THEN 1 ELSE 0 END) retendered,
       SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) closed,
       COALESCE(SUM(tender_value_minor),0) tender_value,
       COALESCE(SUM(estimated_cost_minor),0) estimated_value
     FROM tenders t WHERE t.deleted_at IS NULL ${F}`,
    []
  );
  const projects = db.get(
    `SELECT
       COUNT(*) total,
       SUM(CASE WHEN status IN ('in_progress','awarded','tendered') THEN 1 ELSE 0 END) ongoing,
       SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
       SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) closed
     FROM projects p WHERE p.deleted_at IS NULL ${Fp}`,
    []
  );
  const bills = db.get(
    `SELECT COUNT(*) total, SUM(CASE WHEN status IN ('submitted','checked','certified','approved') THEN 1 ELSE 0 END) pending
     FROM bills b WHERE 1=1 ${Fb}`,
    []
  );
  const payments = db.get(`SELECT COALESCE(SUM(net_amount_minor),0) paid FROM payments pay WHERE pay.status='recorded' ${Fpay}`, []);
  const awards = db.get(
    `SELECT COALESCE(SUM(a.awarded_amount_minor),0) awarded_value FROM awards a JOIN tenders t ON t.id=a.tender_id WHERE a.status != 'cancelled' ${F}`,
    []
  );
  const complianceIssues = db.get(
    `SELECT COUNT(*) c FROM tenders t WHERE t.deleted_at IS NULL ${F} AND t.compliance_status IN ('blocking','warning')`,
    []
  );
  const pendingApprovals = db.get(
    `SELECT COUNT(*) c FROM approval_steps aps
      JOIN tenders t ON t.id = aps.entity_id AND aps.entity_type='tender'
      WHERE aps.status IN ('pending','in_progress') ${F}`,
    []
  );

  return {
    tenders: tenderCounts,
    projects,
    bills: bills,
    payments: { paidMinor: payments.paid },
    awards: { awardedMinor: awards.awarded_value },
    complianceIssues: complianceIssues.c,
    pendingApprovals: pendingApprovals.c,
    statusPipeline: db.all(
      `SELECT status, COUNT(*) c FROM tenders t WHERE t.deleted_at IS NULL ${F} GROUP BY status ORDER BY c DESC`
    ),
    recentActivity: db.all(
      `SELECT * FROM audit_logs ORDER BY id DESC LIMIT 12`
    ),
  };
}

/** Tender register with filters. */
function tenderRegister({ fyId, status, tenderType, schemeId, fundId, q, sort = 'id', dir = 'desc', limit = 50, offset = 0 }) {
  const conds = ['t.deleted_at IS NULL'];
  const params = [];
  if (fyId) { conds.push('t.fy_id = ?'); params.push(fyId); }
  if (status) { conds.push('t.status = ?'); params.push(status); }
  if (tenderType) { conds.push('t.tender_type = ?'); params.push(tenderType); }
  if (schemeId) { conds.push('t.scheme_id = ?'); params.push(schemeId); }
  if (fundId) { conds.push('t.fund_id = ?'); params.push(fundId); }
  if (q) { conds.push('(t.tender_number LIKE ? OR t.title LIKE ? OR t.work_name LIKE ?)'); params.push(`%${q}%`, `%${q}%`, `%${q}%`); }
  const where = conds.join(' AND ');
  const orderCols = { id: 't.id', tender_number: 't.tender_number', estimated: 't.estimated_cost_minor', status: 't.status' };
  const orderCol = orderCols[sort] || 't.id';
  const rows = db.all(
    `SELECT t.*, f.label fy_label, s.name scheme_name, fu.name fund_name,
            (SELECT COUNT(*) FROM tender_bidders b WHERE b.tender_id = t.id) bidder_count
       FROM tenders t
       LEFT JOIN financial_years f ON f.id=t.fy_id
       LEFT JOIN schemes s ON s.id=t.scheme_id
       LEFT JOIN funds fu ON fu.id=t.fund_id
      WHERE ${where} ORDER BY ${orderCol} ${dir === 'asc' ? 'ASC' : 'DESC'} LIMIT ? OFFSET ?`,
    [...params, limit, offset]
  );
  const total = db.get(`SELECT COUNT(*) c FROM tenders t WHERE ${where}`, params).c;
  return { rows, total };
}

function billRegister({ fyId, status, limit = 50, offset = 0 }) {
  const conds = ['1=1'];
  const params = [];
  if (fyId) { conds.push('b.fy_id = ?'); params.push(fyId); }
  if (status) { conds.push('b.status = ?'); params.push(status); }
  const where = conds.join(' AND ');
  const rows = db.all(
    `SELECT b.*, f.label fy_label, p.work_name, c.legal_name
       FROM bills b
       LEFT JOIN financial_years f ON f.id=b.fy_id
       LEFT JOIN projects p ON p.id=b.project_id
       LEFT JOIN contractors c ON c.id=b.contractor_id
      WHERE ${where} ORDER BY b.id DESC LIMIT ? OFFSET ?`,
    [...params, limit, offset]
  );
  const total = db.get(`SELECT COUNT(*) c FROM bills b WHERE ${where}`, params).c;
  return { rows, total };
}

function paymentRegister({ fyId, limit = 50, offset = 0 }) {
  const conds = ['1=1'];
  const params = [];
  if (fyId) { conds.push('pay.fy_id = ?'); params.push(fyId); }
  const where = conds.join(' AND ');
  const rows = db.all(
    `SELECT pay.*, f.label fy_label, p.work_name, c.legal_name, b.bill_number
       FROM payments pay
       LEFT JOIN financial_years f ON f.id=pay.fy_id
       LEFT JOIN projects p ON p.id=pay.project_id
       LEFT JOIN contractors c ON c.id=pay.contractor_id
       LEFT JOIN bills b ON b.id=pay.bill_id
      WHERE ${where} ORDER BY pay.id DESC LIMIT ? OFFSET ?`,
    [...params, limit, offset]
  );
  const total = db.get(`SELECT COUNT(*) c FROM payments pay WHERE ${where}`, params).c;
  return { rows, total };
}

/** Scheme/fund/type/contractor-wise summaries. */
function dimensionSummary(dimension, fyId) {
  const F = fyWhere(fyId);
  let sql;
  if (dimension === 'scheme') {
    sql = `SELECT COALESCE(s.name,'(none)') label, COUNT(*) c, COALESCE(SUM(t.estimated_cost_minor),0) value
             FROM tenders t LEFT JOIN schemes s ON s.id=t.scheme_id
            WHERE t.deleted_at IS NULL ${F} GROUP BY s.name ORDER BY value DESC`;
  } else if (dimension === 'fund') {
    sql = `SELECT COALESCE(f.name,'(none)') label, COUNT(*) c, COALESCE(SUM(t.estimated_cost_minor),0) value
             FROM tenders t LEFT JOIN funds f ON f.id=t.fund_id
            WHERE t.deleted_at IS NULL ${F} GROUP BY f.name ORDER BY value DESC`;
  } else if (dimension === 'type') {
    sql = `SELECT t.tender_type label, COUNT(*) c, COALESCE(SUM(t.estimated_cost_minor),0) value
             FROM tenders t WHERE t.deleted_at IS NULL ${F} GROUP BY t.tender_type ORDER BY value DESC`;
  } else if (dimension === 'contractor') {
    sql = `SELECT c.legal_name label, COUNT(*) c, COALESCE(SUM(a.awarded_amount_minor),0) value
             FROM awards a JOIN tenders t ON t.id=a.tender_id
             JOIN contractors c ON c.id=a.contractor_id
            WHERE a.status != 'cancelled' ${F} GROUP BY c.legal_name ORDER BY value DESC`;
  }
  return db.all(sql, []);
}

/** Compliance & risk center. */
function complianceCenter(fyId) {
  const F = fyWhere(fyId);
  const blockers = db.all(
    `SELECT t.id, t.tender_number, t.title, t.compliance_status FROM tenders t
      WHERE t.deleted_at IS NULL ${F} AND t.compliance_status='blocking' ORDER BY t.id DESC`
  );
  const warnings = db.all(
    `SELECT t.id, t.tender_number, t.title, t.compliance_status FROM tenders t
      WHERE t.deleted_at IS NULL ${F} AND t.compliance_status='warning' ORDER BY t.id DESC`
  );
  const expiring = db.all(
    `SELECT cd.*, c.legal_name FROM contractor_documents cd JOIN contractors c ON c.id=cd.contractor_id
      WHERE cd.expiry_status IN ('expired','expiring_soon') ORDER BY cd.expiry_date ASC LIMIT 50`
  );
  const pendingApprovals = db.all(
    `SELECT aps.*, t.tender_number FROM approval_steps aps
      JOIN tenders t ON t.id=aps.entity_id AND aps.entity_type='tender'
      WHERE aps.status IN ('pending','in_progress') ${F} ORDER BY aps.id`
  );
  const anomalies = {
    overruns: db.all(
      `SELECT mi.*, m.measurement_number, p.work_name FROM measurement_items mi
        JOIN measurements m ON m.id=mi.measurement_id
        JOIN projects p ON p.id=m.project_id
        WHERE mi.overrun_flag = 1 ORDER BY mi.id DESC LIMIT 50`
    ),
    delayedWorks: db.all(
      `SELECT p.*, f.label fy_label FROM projects p LEFT JOIN financial_years f ON f.id=p.fy_id
        WHERE p.status IN ('awarded','in_progress') AND p.planned_completion_date IS NOT NULL
          AND p.planned_completion_date < date('now') AND p.actual_completion_date IS NULL ORDER BY p.planned_completion_date`
    ),
  };
  return { blockers, warnings, expiring, pendingApprovals, anomalies };
}

/** Reconciliation: sanctioned → estimate → tender → award → certified → paid. */
function reconciliation(fyId) {
  const F = fyWhere(fyId);
  return db.all(
    `SELECT t.id, t.tender_number, t.title, f.label fy_label,
            t.estimated_cost_minor, t.tender_value_minor,
            COALESCE(a.awarded,0) awarded_minor,
            COALESCE(b.certified,0) certified_minor,
            COALESCE(pay.paid,0) paid_minor
       FROM tenders t
       LEFT JOIN financial_years f ON f.id=t.fy_id
       LEFT JOIN (SELECT tender_id, SUM(awarded_amount_minor) awarded FROM awards WHERE status!='cancelled' GROUP BY tender_id) a ON a.tender_id=t.id
       LEFT JOIN (SELECT tender_id, SUM(current_bill_minor) certified FROM bills WHERE status IN ('certified','approved','paid','partially_paid') GROUP BY tender_id) b ON b.tender_id=t.id
       LEFT JOIN (SELECT tender_id, SUM(net_amount_minor) paid FROM payments pay JOIN bills b2 ON b2.id=pay.bill_id WHERE pay.status='recorded' GROUP BY b2.tender_id) pay ON pay.tender_id=t.id
      WHERE t.deleted_at IS NULL ${F} ORDER BY t.id DESC LIMIT 200`,
    []
  );
}

module.exports = { dashboard, tenderRegister, billRegister, paymentRegister, dimensionSummary, complianceCenter, reconciliation };
