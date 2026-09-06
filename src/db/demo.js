'use strict';

/**
 * Development-only DEMO seed data.
 *
 * All demo records are clearly labelled with a "[DEMO]" prefix and are
 * intended for development/testing only. This script never creates fake
 * official approvals or fake government signatures — every approval recorded
 * here is a system record marked as sample data.
 */

const db = require('./database');
const { uuid } = require('../util/uuid');
const tenderService = require('../services/tenderService');
const contractorService = require('../services/contractorService');
const bidService = require('../services/bidService');
const awardService = require('../services/awardService');
const lifecycleService = require('../services/lifecycleService');
const financialService = require('../services/financialService');

const DEMO_MARKER = 'demo.seeded';

function isSeeded() {
  return !!db.get('SELECT key FROM settings WHERE key = ?', [DEMO_MARKER]);
}

async function seedDemo() {
  if (isSeeded()) return { seeded: false, reason: 'already-seeded' };
  const admin = db.get('SELECT * FROM users WHERE is_global_admin = 1');
  if (!admin) throw new Error('Admin user required for demo seed');
  const actor = admin;
  const fy = db.get("SELECT * FROM financial_years WHERE label = '2026-27'");

  // Contractors (clearly labelled).
  const c1 = contractorService.createContractor({ legal_name: '[DEMO] Sample Contractor Alpha', business_name: 'Demo Firm', registration_class: 'Class I', pan: 'DEMOP1234A' }, actor);
  const c2 = contractorService.createContractor({ legal_name: '[DEMO] Sample Contractor Beta', registration_class: 'Class II' }, actor);

  // Project.
  const r = db.run(
    `INSERT INTO projects (uid, fy_id, scheme_id, fund_id, work_name, description, location,
       administrative_approval_no, administrative_approval_date, technical_sanction_no, technical_sanction_date,
       estimate_amount_minor, sanctioned_amount_minor, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [uuid(), fy.id, 1, 1, '[DEMO] Construction of CC road (sample data)', 'Demo project for testing the full lifecycle', 'Demo Mouza', 'DEMO-AA-001', '2026-06-01', 'DEMO-TS-001', '2026-06-05', 100000000, 115000000, 'planned', actor.id]
  );
  const projectId = r.lastInsertRowid;

  // Tender.
  const tender = tenderService.createTender({
    fy_id: fy.id, procurement_category: 'works', tender_type: 'open', ruleset_id: 1,
    project_id: projectId, scheme_id: 1, fund_id: 1,
    work_name: '[DEMO] Construction of CC road (sample data)', title: 'CC road', location: 'Demo Mouza',
  }, actor);
  tenderService.updateTender(tender.id, {
    admin_approval_no: 'DEMO-AA-001', admin_approval_date: '2026-06-01', admin_approval_authority: 'Pradhan (DEMO)',
    tech_sanction_no: 'DEMO-TS-001', tech_sanction_date: '2026-06-05', tech_sanction_authority: 'EO (DEMO)',
    estimated_cost_minor: 100000000, tender_value_minor: 100000000, emd_minor: 2000000, tender_fee_minor: 50000,
    completion_period_days: 90, publication_date: '2026-07-01', bid_close_date: '2026-07-15',
    technical_open_date: '2026-07-16', financial_open_date: '2026-07-18',
  }, actor);

  db.run(`INSERT INTO boq_items (uid, tender_id, item_no, description, specification, unit, quantity, estimated_rate_minor) VALUES
    (?,?,?,?,?,?,?,?), (?,?,?,?,?,?,?,?)`,
    [uuid(), tender.id, '1', 'Earthwork excavation', 'All kinds of soil', 'cum', 100, 25000,
     uuid(), tender.id, '2', 'CC M20 concrete', '1:1.5:3', 'cum', 50, 550000]);

  tenderService.runCompliance(tender.id, actor);
  tenderService.submitForApproval(tender.id, actor);
  for (let i = 0; i < 5; i++) tenderService.workflowAction(tender.id, 'approve', 'DEMO approval', actor);
  await tenderService.generateNIT(tender.id, actor);
  tenderService.publish(tender.id, { publicationDate: '2026-07-01' }, actor);
  tenderService.startBidding(tender.id, actor);

  const b1 = bidService.addBidder(tender.id, { contractorId: c1.id }, actor);
  const b2 = bidService.addBidder(tender.id, { contractorId: c2.id }, actor);
  tenderService.closeBids(tender.id, actor);
  bidService.recordTechnicalOpening(tender.id, { openingDate: '2026-07-16' }, actor);

  const k1 = bidService.addCriterion(tender.id, { code: 'REG', criterion: 'Valid Registration' }, actor);
  const k2 = bidService.addCriterion(tender.id, { code: 'EXP', criterion: 'Similar Work Experience' }, actor);
  bidService.setEvaluation(tender.id, b1.id, k1.id, { result: 'pass' }, actor);
  bidService.setEvaluation(tender.id, b1.id, k2.id, { result: 'pass' }, actor);
  bidService.setEvaluation(tender.id, b2.id, k1.id, { result: 'pass' }, actor);
  bidService.setEvaluation(tender.id, b2.id, k2.id, { result: 'fail', remarks: 'No experience' }, actor);
  bidService.finalizeTechnicalEvaluation(tender.id, { rejectionReasons: { [b2.id]: 'No similar work experience (DEMO)' } }, actor);

  bidService.recordFinancialBid(tender.id, b1.id, { totalAmountMinor: 95000000 }, actor);
  bidService.computeRankings(tender.id, actor);

  const award = awardService.recommendAward(tender.id, { contractorId: c1.id, awardedAmountMinor: 95000000 }, actor);
  awardService.approveAward(award.id, { authority: 'Pradhan (DEMO)' }, actor);
  await awardService.issueLOA(award.id, actor);
  awardService.createAgreement(award.id, { executionDate: '2026-07-25' }, actor);
  awardService.issueWorkOrder(award.id, { startDate: '2026-08-01', completionDate: '2026-10-29' }, actor);

  // Execution, measurement, bill, payment, completion (full demo lifecycle).
  lifecycleService.recordProgress(projectId, { progressDate: '2026-08-15', physicalProgress: 40, financialProgress: 30 }, actor);
  const m = lifecycleService.createMeasurement(projectId, { measurementDate: '2026-08-20' }, actor);
  const boq1 = db.get("SELECT id FROM boq_items WHERE tender_id = ? AND item_no = '1'", [tender.id]).id;
  lifecycleService.addMeasurementItem(m.id, { boqItemId: boq1, itemNo: '1', description: 'Earthwork excavation', unit: 'cum', currentQuantity: 40, rateMinor: 25000 }, actor);
  lifecycleService.setMeasurementStatus(m.id, 'approved', actor);

  const bill = lifecycleService.createBill(projectId, { billType: 'running' }, actor);
  lifecycleService.updateBillDraft(bill.id, { grossWorkValueMinor: 1000000, retentionPct: 5, deductionsMinor: 0, recoveriesMinor: 0 }, actor);
  lifecycleService.submitBill(bill.id, actor);
  for (let i = 0; i < 4; i++) lifecycleService.billWorkflowAction(bill.id, 'approve', 'DEMO bill approval', actor);
  lifecycleService.recordPayment(bill.id, { netAmountMinor: 950000, paymentMethod: 'bank_transfer', transactionReference: 'DEMO-TXN-0001' }, actor);
  lifecycleService.advanceCompletion(projectId, { status: 'closed', finalMeasurementId: m.id, finalBillId: bill.id }, actor);

  db.run("UPDATE settings SET value = '1', updated_at = CURRENT_TIMESTAMP WHERE key = ?", [DEMO_MARKER]);
  db.run("INSERT INTO settings (key, value) VALUES (?, '1') ON CONFLICT(key) DO UPDATE SET value='1'", [DEMO_MARKER]);

  return {
    seeded: true,
    tender: tender.tender_number,
    projectId,
    contractors: [c1.legal_name, c2.legal_name],
  };
}

async function resetDemo() {
  db.run('DELETE FROM settings WHERE key = ?', [DEMO_MARKER]);
  // Remove demo-labelled records (keep it simple: cascade is limited, so delete in order).
  const demoProjects = db.all("SELECT id FROM projects WHERE work_name LIKE '[DEMO]%'").map((r) => r.id);
  for (const pid of demoProjects) {
    db.run('DELETE FROM payments WHERE project_id = ?', [pid]);
    db.run('DELETE FROM bill_items WHERE bill_id IN (SELECT id FROM bills WHERE project_id = ?)', [pid]);
    db.run('DELETE FROM bills WHERE project_id = ?', [pid]);
    db.run('DELETE FROM measurement_items WHERE measurement_id IN (SELECT id FROM measurements WHERE project_id = ?)', [pid]);
    db.run('DELETE FROM measurements WHERE project_id = ?', [pid]);
    db.run('DELETE FROM work_progress WHERE project_id = ?', [pid]);
    db.run('DELETE FROM completions WHERE project_id = ?', [pid]);
  }
  db.run("DELETE FROM tenders WHERE work_name LIKE '[DEMO]%'");
  db.run("DELETE FROM projects WHERE work_name LIKE '[DEMO]%'");
  db.run("DELETE FROM contractors WHERE legal_name LIKE '[DEMO]%'");
  return { reset: true };
}

module.exports = { seedDemo, resetDemo, isSeeded };

if (require.main === module) {
  const { migrate } = require('./database');
  migrate(require('./migrations'));
  if (process.argv.includes('--reset')) resetDemo();
  seedDemo().then((r) => {
    // eslint-disable-next-line no-console
    console.log('[demo]', r);
    db.closeDb();
  });
}
