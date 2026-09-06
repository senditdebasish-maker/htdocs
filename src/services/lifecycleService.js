'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { json } = require('../db/database');
const money = require('../util/money');
const dates = require('../util/dates');
const { validation, notFound, conflict } = require('../util/errors');
const audit = require('./auditService');
const notifications = require('./notificationService');
const financialService = require('./financialService');
const numbering = require('./numberingService');
const fyService = require('./fyService');

// ============================= Execution =============================

function recordProgress(projectId, { progressDate, physicalProgress, financialProgress, milestone = null, notes = null }, actor) {
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  if (!p) throw notFound('Project not found');
  const r = db.run(
    `INSERT INTO work_progress (uid, project_id, progress_date, physical_progress, financial_progress, milestone, notes, created_by)
     VALUES (?,?,?,?,?,?,?,?)`,
    [uuid(), projectId, progressDate || dates.todayISO(), physicalProgress, financialProgress, milestone || null, notes || null, actor ? actor.id : null]
  );
  db.run('UPDATE projects SET physical_progress = ?, financial_progress = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [physicalProgress, financialProgress, projectId]);
  if (Number(physicalProgress) >= 100 && p.status !== 'completed') {
    db.run("UPDATE projects SET status = 'completed', actual_completion_date = ? WHERE id = ?", [progressDate || dates.todayISO(), projectId]);
  }
  audit.record({ actor, action: 'execution.progress', entityType: 'project', entityId: projectId, entityLabel: p.work_name, newValue: { physicalProgress, financialProgress } });
  return db.get('SELECT * FROM work_progress WHERE id = ?', [r.lastInsertRowid]);
}

function addExtensionRequest(projectId, { requestedDays, reason }, actor) {
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  if (!p) throw notFound('Project not found');
  const r = db.run(
    `INSERT INTO extension_requests (uid, project_id, requested_days, reason) VALUES (?,?,?,?)`,
    [uuid(), projectId, requestedDays, reason]
  );
  audit.record({ actor, action: 'execution.extension_request', entityType: 'project', entityId: projectId, entityLabel: p.work_name, reason });
  return db.get('SELECT * FROM extension_requests WHERE id = ?', [r.lastInsertRowid]);
}

function decideExtension(id, { status, remarks = null }, actor) {
  const e = db.get('SELECT * FROM extension_requests WHERE id = ?', [id]);
  if (!e) throw notFound('Extension request not found');
  if (e.status !== 'pending') throw conflict('Extension already decided');
  db.run('UPDATE extension_requests SET status = ?, decided_by = ?, decided_at = CURRENT_TIMESTAMP, remarks = ? WHERE id = ?', [status, actor ? actor.id : null, remarks, id]);
  audit.record({ actor, action: `execution.extension_${status}`, entityType: 'extension_request', entityId: id, reason: remarks });
  return db.get('SELECT * FROM extension_requests WHERE id = ?', [id]);
}

// ============================= Measurements =============================

function createMeasurement(projectId, { measurementDate = null, location = null, remarks = null }, actor) {
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  if (!p) throw notFound('Project not found');
  const tender = db.get('SELECT * FROM tenders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [projectId]);
  const number = numbering.nextMeasurementNumber(projectId);
  const r = db.run(
    `INSERT INTO measurements (uid, project_id, tender_id, measurement_number, measurement_date, location, remarks, measured_by)
     VALUES (?,?,?,?,?,?,?,?)`,
    [uuid(), projectId, tender ? tender.id : null, number, measurementDate || dates.todayISO(), location || null, remarks || null, actor ? actor.id : null]
  );
  audit.record({ actor, action: 'measurement.create', entityType: 'measurement', entityId: r.lastInsertRowid, entityLabel: number });
  return db.get('SELECT * FROM measurements WHERE id = ?', [r.lastInsertRowid]);
}

/** Add a measurement item; warns (flag) when cumulative quantity exceeds BOQ. */
function addMeasurementItem(measurementId, { boqItemId = null, itemNo = null, description, unit = null, previousQuantity = 0, currentQuantity = 0, rateMinor = 0, remarks = null }, actor) {
  const m = db.get('SELECT * FROM measurements WHERE id = ?', [measurementId]);
  if (!m) throw notFound('Measurement not found');
  if (!['draft', 'checked'].includes(m.status)) throw conflict('Measurement is locked');
  const cumulative = money.toNumber(previousQuantity) + money.toNumber(currentQuantity);
  let overrun = 0;
  if (boqItemId) {
    const boq = db.get('SELECT * FROM boq_items WHERE id = ?', [boqItemId]);
    if (boq && cumulative > boq.quantity + 1e-9) overrun = 1;
  }
  const rateMinorInt = Math.round(Number(rateMinor) || 0);
  const amount = money.qtyRateMinor(currentQuantity, rateMinorInt);
  const r = db.run(
    `INSERT INTO measurement_items (uid, measurement_id, boq_item_id, item_no, description, unit, previous_quantity, current_quantity, cumulative_quantity, rate_minor, amount_minor, overrun_flag, remarks)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [uuid(), measurementId, boqItemId, itemNo, description, unit, previousQuantity, currentQuantity, cumulative, rateMinorInt, amount, overrun, remarks || null]
  );
  audit.record({ actor, action: 'measurement.item_add', entityType: 'measurement', entityId: measurementId, entityLabel: `${m.measurement_number} item ${itemNo}`, newValue: { currentQuantity, overrun } });
  return db.get('SELECT * FROM measurement_items WHERE id = ?', [r.lastInsertRowid]);
}

function setMeasurementStatus(measurementId, status, actor, role) {
  const m = db.get('SELECT * FROM measurements WHERE id = ?', [measurementId]);
  if (!m) throw notFound('Measurement not found');
  if (typeof status !== 'string' || !['checked', 'approved', 'final'].includes(status)) {
    throw validation('Invalid measurement status');
  }
  const order = { draft: 0, checked: 1, approved: 2, final: 3 };
  const cur = order[m.status];
  const target = order[status];
  if (target < cur) throw conflict('Cannot move measurement backwards');
  if (status === 'checked') db.run('UPDATE measurements SET status = ?, checked_by = ? WHERE id = ?', [status, actor ? actor.id : null, measurementId]);
  else if (status === 'approved') db.run('UPDATE measurements SET status = ?, approved_by = ? WHERE id = ?', [status, actor ? actor.id : null, measurementId]);
  else if (status === 'final') db.run('UPDATE measurements SET status = ?, approved_by = ? WHERE id = ?', [status, actor ? actor.id : null, measurementId]);
  audit.record({ actor, action: `measurement.${status}`, entityType: 'measurement', entityId: measurementId, entityLabel: m.measurement_number });
  return db.get('SELECT * FROM measurements WHERE id = ?', [measurementId]);
}

// ============================= Bills =============================

function createBill(projectId, { billDate = null, billType = 'running' }, actor) {
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  if (!p) throw notFound('Project not found');
  const fy = fyService.resolveFYForDate(billDate || dates.todayISO());
  const wo = db.get('SELECT * FROM work_orders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [projectId]);
  const number = numbering.nextBillNumber(fy.id);
  const r = db.run(
    `INSERT INTO bills (uid, fy_id, project_id, tender_id, work_order_id, contractor_id, bill_number, bill_type, bill_date, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
    [uuid(), fy.id, projectId, wo ? wo.tender_id : null, wo ? wo.id : null, p.contractor_id, number, billType, billDate || dates.todayISO(), 'draft', actor ? actor.id : null]
  );
  audit.record({ actor, action: 'bill.create', entityType: 'bill', entityId: r.lastInsertRowid, entityLabel: number });
  return db.get('SELECT * FROM bills WHERE id = ?', [r.lastInsertRowid]);
}

/** Update a draft bill with computed values. All money inputs in MINOR units. */
function updateBillDraft(billId, { grossWorkValueMinor, previousCertifiedMinor = 0, retentionPct = 0, taxAmountMinor = 0, deductionsMinor = 0, recoveriesMinor = 0, remarks = null }, actor) {
  const b = db.get('SELECT * FROM bills WHERE id = ?', [billId]);
  if (!b) throw notFound('Bill not found');
  if (b.status !== 'draft') throw conflict('Only draft bills can be edited');
  const c = financialService.computeBill({ grossWorkValueMinor, previousCertifiedMinor, retentionPct, taxAmountMinor, deductionsMinor, recoveriesMinor });
  db.run(
    `UPDATE bills SET gross_work_value_minor = ?, previous_certified_minor = ?, current_bill_minor = ?, cumulative_minor = ?,
       retention_minor = ?, tax_minor = ?, deductions_minor = ?, recoveries_minor = ?, net_payable_minor = ?, remarks = ?
     WHERE id = ?`,
    [c.grossMinor, c.previousCertifiedMinor, c.currentMinor, c.cumulativeMinor, c.retentionMinor, c.taxMinor, c.deductionsMinor, Math.round(Number(recoveriesMinor) || 0), c.netPayableMinor, remarks, billId]
  );
  audit.record({ actor, action: 'bill.update', entityType: 'bill', entityId: billId, entityLabel: b.bill_number });
  return db.get('SELECT * FROM bills WHERE id = ?', [billId]);
}

function submitBill(billId, actor) {
  const b = db.get('SELECT * FROM bills WHERE id = ?', [billId]);
  if (!b) throw notFound('Bill not found');
  if (b.status !== 'draft') throw conflict('Bill is not in draft');
  db.run("UPDATE bills SET status = 'submitted' WHERE id = ?", [billId]);
  workflowInit('bill', billId);
  audit.record({ actor, action: 'bill.submit', entityType: 'bill', entityId: billId, entityLabel: b.bill_number });
  notifications.notify({ roleCodes: ['technical_officer'], type: 'bill_submitted', title: 'Bill submitted', body: `${b.bill_number} awaiting technical check.`, entityType: 'bill', entityId: billId });
  return db.get('SELECT * FROM bills WHERE id = ?', [billId]);
}

function workflowInit(entityType, entityId) {
  const workflow = require('./workflowService');
  workflow.init(entityType, entityId);
}

/** Advance bill through check/certify/approve workflow. */
function billWorkflowAction(billId, action, remarks, actor) {
  const b = db.get('SELECT * FROM bills WHERE id = ?', [billId]);
  if (!b) throw notFound('Bill not found');
  if (!['submitted', 'checked', 'certified', 'returned'].includes(b.status)) throw conflict('Bill is not in a workflow state');
  const workflow = require('./workflowService');
  const map = { approve: null, reject: 'rejected', return: 'returned', clarification: 'returned' };
  const result = workflow.act('bill', billId, {
    action,
    remarks,
    actor,
    onComplete: () => {
      db.run("UPDATE bills SET status = 'approved', approved_by = ? WHERE id = ?", [actor ? actor.id : null, billId]);
      notifications.notify({ roleCodes: ['accounts_officer'], type: 'bill_approved', title: 'Bill approved', body: `${b.bill_number} approved for payment.`, entityType: 'bill', entityId: billId });
    },
  });
  if (map[action] && action !== 'approve') {
    db.run(`UPDATE bills SET status = ? WHERE id = ?`, [map[action], billId]);
    audit.record({ actor, action: `bill.${action}`, entityType: 'bill', entityId: billId, entityLabel: b.bill_number, reason: remarks });
    return db.get('SELECT * FROM bills WHERE id = ?', [billId]);
  }
  if (action === 'approve') {
    const step = result.step;
    const statusByStep = { 'Technical Check': 'checked', 'Accounts Certification': 'certified', 'Approval': 'approved' };
    const s = statusByStep[step.step_name];
    if (s) {
      const setter = { checked: null, certified: 'certified_by', approved: 'approved_by' }[s];
      if (setter) db.run(`UPDATE bills SET status = ?, ${setter} = ? WHERE id = ?`, [s, actor ? actor.id : null, billId]);
      else db.run('UPDATE bills SET status = ? WHERE id = ?', [s, billId]);
    }
  }
  audit.record({ actor, action: `bill.${action}`, entityType: 'bill', entityId: billId, entityLabel: b.bill_number, reason: remarks });
  return db.get('SELECT * FROM bills WHERE id = ?', [billId]);
}

// ============================= Payments =============================

/** Record a payment against a bill with overpayment & duplicate protection. Money in MINOR. */
function recordPayment(billId, { paymentDate = null, grossAmountMinor = null, deductionsMinor = 0, netAmountMinor = null, paymentMethod = null, transactionReference = null, remarks = null }, actor) {
  const b = db.get('SELECT * FROM bills WHERE id = ?', [billId]);
  if (!b) throw notFound('Bill not found');
  if (!['certified', 'approved'].includes(b.status)) throw conflict('Bill must be certified/approved before payment');
  const alreadyPaid = db.get('SELECT COALESCE(SUM(net_amount_minor),0) s FROM payments WHERE bill_id = ? AND status = ?', [billId, 'recorded']).s;
  const gross = Math.round(Number(grossAmountMinor ?? netAmountMinor) || 0);
  const ded = Math.round(Number(deductionsMinor) || 0);
  const net = Math.round(Number(netAmountMinor) || 0) || (gross - ded);
  if (net <= 0) throw validation('Payment amount must be positive');
  const certifiedNet = b.net_payable_minor;
  if (certifiedNet > 0 && alreadyPaid + net > certifiedNet) {
    throw conflict('Payment would exceed the certified bill amount');
  }
  const fy = fyService.resolveFYForDate(paymentDate || dates.todayISO());
  const voucherNo = `PVR/${fy.label}/${String(db.get('SELECT COUNT(*) c FROM payments WHERE fy_id = ?', [fy.id]).c + 1).padStart(4, '0')}`;
  const r = db.run(
    `INSERT INTO payments (uid, fy_id, bill_id, project_id, contractor_id, voucher_no, payment_date, gross_amount_minor, deductions_minor, net_amount_minor, payment_method, transaction_reference, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), fy.id, billId, b.project_id, b.contractor_id, voucherNo, paymentDate || dates.todayISO(),
      gross, ded, net,
      paymentMethod || null, transactionReference || null, 'recorded', actor ? actor.id : null,
    ]
  );
  // Update bill status to paid / partially paid.
  const totalPaid = alreadyPaid + net;
  const newStatus = totalPaid >= certifiedNet ? 'paid' : 'partially_paid';
  db.run('UPDATE bills SET status = ? WHERE id = ?', [newStatus, billId]);
  audit.record({ actor, action: 'payment.record', entityType: 'payment', entityId: r.lastInsertRowid, entityLabel: voucherNo, newValue: { net, voucherNo }, reason: 'Payment recorded (no bank integration — see notes)' });
  return db.get('SELECT * FROM payments WHERE id = ?', [r.lastInsertRowid]);
}

// ============================= Completion =============================

function getOrCreateCompletion(projectId) {
  const existing = db.get('SELECT * FROM completions WHERE project_id = ? ORDER BY id DESC LIMIT 1', [projectId]);
  if (existing) return existing;
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  if (!p) throw notFound('Project not found');
  const tender = db.get('SELECT * FROM tenders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [projectId]);
  const r = db.run(
    'INSERT INTO completions (uid, project_id, tender_id) VALUES (?,?,?)',
    [uuid(), projectId, tender ? tender.id : null]
  );
  return db.get('SELECT * FROM completions WHERE id = ?', [r.lastInsertRowid]);
}

function advanceCompletion(projectId, { status, date = null, finalMeasurementId = null, finalBillId = null, finalPaymentId = null }, actor) {
  const comp = getOrCreateCompletion(projectId);
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  const map = {
    inspection_done: { col: 'inspection_date' },
    final_measurement_done: { col: null },
    final_bill_done: { col: null },
    final_payment_done: { col: null },
    security_released: { col: 'security_release_date' },
    closed: { col: 'handover_date' },
  };
  if (!map[status]) throw validation('Invalid completion status');
  const updates = ['status = ?'];
  const params = [status];
  if (map[status].col && date) { updates.push(`${map[status].col} = ?`); params.push(date); }
  if (finalMeasurementId) { updates.push('final_measurement_id = ?'); params.push(finalMeasurementId); }
  if (finalBillId) { updates.push('final_bill_id = ?'); params.push(finalBillId); }
  if (finalPaymentId) { updates.push('final_payment_id = ?'); params.push(finalPaymentId); }
  params.push(comp.id);
  db.run(`UPDATE completions SET ${updates.join(', ')} WHERE id = ?`, params);
  if (status === 'closed') {
    db.run("UPDATE projects SET status = 'closed', actual_completion_date = ? WHERE id = ?", [date || dates.todayISO(), projectId]);
  }
  audit.record({ actor, action: `completion.${status}`, entityType: 'project', entityId: projectId, entityLabel: p.work_name });
  return db.get('SELECT * FROM completions WHERE id = ?', [comp.id]);
}

/** Generate completion certificate PDF. */
async function generateCompletionCertificate(projectId, actor) {
  const comp = getOrCreateCompletion(projectId);
  const builder = documentGenBuild(comp);
  const pdfService = require('./pdfService');
  const result = await pdfService.renderPdf(builder, `Completion-${projectId}`, {
    docType: 'completion_certificate', entityType: 'project', entityId: projectId, generatedBy: actor ? actor.id : null,
  });
  db.run('UPDATE completions SET certificate_document_id = ? WHERE id = ?', [result.document.id, comp.id]);
  return { ...result, completion: db.get('SELECT * FROM completions WHERE id = ?', [comp.id]) };
}

function documentGenBuild(completion) {
  const documentGen = require('./documentGeneration');
  return documentGen.buildCompletionCertificate(completion.id);
}

module.exports = {
  recordProgress,
  addExtensionRequest,
  decideExtension,
  createMeasurement,
  addMeasurementItem,
  setMeasurementStatus,
  createBill,
  updateBillDraft,
  submitBill,
  billWorkflowAction,
  recordPayment,
  getOrCreateCompletion,
  advanceCompletion,
  generateCompletionCertificate,
};
