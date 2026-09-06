'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { freshApp, db } = require('../helpers/setup');
const compliance = require('../../src/services/complianceService');
const tenderService = require('../../src/services/tenderService');

test('compliance engine blocks on missing approvals, passes when present', () => {
  freshApp();
  const fy = db.get("SELECT * FROM financial_years WHERE label = '2026-27'");
  // Create a tender without approvals.
  const t = tenderService.createTender({ fy_id: fy.id, procurement_category: 'works', tender_type: 'open', work_name: 'Test Work' }, null);
  let res = compliance.evaluateTender(t, {});
  const missingApproval = res.findings.find((f) => f.ruleCode === 'ADMIN_APPROVAL_REQUIRED');
  assert.strictEqual(missingApproval.status, 'fail');
  assert.strictEqual(missingApproval.blocksWorkflow, true);
  assert.ok(res.summary.blocking >= 1);

  // Add approvals + BOQ item; re-evaluate.
  tenderService.updateTender(t.id, {
    admin_approval_no: 'AA-1', admin_approval_date: '2026-06-01',
    tech_sanction_no: 'TS-1', tech_sanction_date: '2026-06-02',
    estimated_cost_minor: 100000000, publication_date: '2026-07-01', bid_close_date: '2026-07-15',
  }, null);
  db.run("INSERT INTO boq_items (uid, tender_id, item_no, description, quantity, estimated_rate_minor) VALUES ('b1', ?, '1', 'Item', 10, 10000)", [t.id]);
  res = compliance.evaluateTender(tenderService.getTender(t.id), {});
  const approval = res.findings.find((f) => f.ruleCode === 'ADMIN_APPROVAL_REQUIRED');
  assert.strictEqual(approval.status, 'pass');
  assert.strictEqual(res.summary.blocking, 0);
});

test('notice period tier produces a warning when too short', () => {
  freshApp();
  const fy = db.get("SELECT * FROM financial_years WHERE label = '2026-27'");
  const t = tenderService.createTender({ fy_id: fy.id, procurement_category: 'works', tender_type: 'open', work_name: 'W' }, null);
  tenderService.updateTender(t.id, {
    admin_approval_no: 'AA', tech_sanction_no: 'TS',
    estimated_cost_minor: 100000000, publication_date: '2026-07-10', bid_close_date: '2026-07-12', // 2 days < 7
  }, null);
  const res = compliance.evaluateTender(tenderService.getTender(t.id), {});
  const notice = res.findings.find((f) => f.ruleCode === 'NOTICE_PERIOD_TIERS');
  assert.strictEqual(notice.status, 'fail');
  assert.strictEqual(notice.blocksWorkflow, false);
});

test('e-tender mandatory warning for works ≥ ₹5 lakh', () => {
  freshApp();
  const fy = db.get("SELECT * FROM financial_years WHERE label = '2026-27'");
  const t = tenderService.createTender({ fy_id: fy.id, procurement_category: 'works', tender_type: 'open', work_name: 'W' }, null);
  tenderService.updateTender(t.id, {
    admin_approval_no: 'AA', tech_sanction_no: 'TS',
    estimated_cost_minor: 60000000, // ₹6 lakh
  }, null);
  const res = compliance.evaluateTender(tenderService.getTender(t.id), {});
  const et = res.findings.find((f) => f.ruleCode === 'ETENDER_MANDATORY_WORKS_5L');
  assert.strictEqual(et.status, 'fail');
  // Findings cite a source.
  assert.ok(et.source && et.source.reference_number);
});

test('manual rules yield "Verification Required" findings, never false compliance', () => {
  freshApp();
  const fy = db.get("SELECT * FROM financial_years WHERE label = '2026-27'");
  const t = tenderService.createTender({ fy_id: fy.id, procurement_category: 'works', tender_type: 'open', work_name: 'W' }, null);
  const res = compliance.evaluateTender(tenderService.getTender(t.id), {});
  const v = res.findings.find((f) => f.ruleCode === 'TWO_BID_SYSTEM');
  assert.strictEqual(v.status, 'verification');
  assert.ok(v.message.includes('Verification Required'));
});
