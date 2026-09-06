'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { freshApp, db } = require('../helpers/setup');
const financial = require('../../src/services/financialService');
const { uuid } = require('../../src/util/uuid');

test('boqTotals computes quantity × rate in minor units', () => {
  freshApp();
  // Manually create a tender row to attach BOQ items to.
  const fy = db.get("SELECT id FROM financial_years WHERE label = '2026-27'");
  const t = db.run("INSERT INTO tenders (uid, fy_id, tender_number, tender_type, procurement_category, title, status) VALUES (?,?,?,?,?,?, 'draft')",
    [uuid(), fy.id, 'T/1', 'open', 'works', 'Test']).lastInsertRowid;
  db.run("INSERT INTO boq_items (uid, tender_id, item_no, description, quantity, estimated_rate_minor, tax_pct) VALUES (?,?,?,?,?,?,?)",
    [uuid(), t, '1', 'Earthwork', 100, 25000, 0]);
  db.run("INSERT INTO boq_items (uid, tender_id, item_no, description, quantity, estimated_rate_minor, tax_pct) VALUES (?,?,?,?,?,?,?)",
    [uuid(), t, '2', 'Concrete', 50, 550000, 0]);
  const totals = financial.boqTotals(t);
  assert.strictEqual(totals.baseMinor, 30000000); // ₹3,00,000
  assert.strictEqual(totals.totalMinor, 30000000);
});

test('computeBill applies retention and deductions correctly', () => {
  const b = financial.computeBill({
    grossWorkValueMinor: 1000000, // ₹10,000
    previousCertifiedMinor: 0,
    retentionPct: 5,
    taxAmountMinor: 0,
    deductionsMinor: 10000,
    recoveriesMinor: 0,
  });
  assert.strictEqual(b.currentMinor, 1000000);
  assert.strictEqual(b.retentionMinor, 50000);
  assert.strictEqual(b.netPayableMinor, 940000); // 1,000,000 - 50,000 - 10,000
});

test('computeBill never returns negative net payable', () => {
  const b = financial.computeBill({ grossWorkValueMinor: 500, previousCertifiedMinor: 0, deductionsMinor: 999999 });
  assert.strictEqual(b.netPayableMinor, 0);
});

test('rankFinancialBids assigns L1/L2/L3 by lowest total', () => {
  freshApp();
  const fy = db.get("SELECT id FROM financial_years WHERE label = '2026-27'");
  const t = db.run("INSERT INTO tenders (uid, fy_id, tender_number, tender_type, procurement_category, title, status) VALUES (?,?,?,?,?,?, 'financial_evaluation')",
    [uuid(), fy.id, 'T/2', 'open', 'works', 'Test']).lastInsertRowid;
  const c1 = db.run("INSERT INTO contractors (uid, contractor_code, legal_name) VALUES (?,?,?)", [uuid(), 'C1', 'A']).lastInsertRowid;
  const c2 = db.run("INSERT INTO contractors (uid, contractor_code, legal_name) VALUES (?,?,?)", [uuid(), 'C2', 'B']).lastInsertRowid;
  const b1 = db.run("INSERT INTO tender_bidders (uid, tender_id, contractor_id, bid_status) VALUES (?,?,?, 'technically_qualified')", [uuid(), t, c1]).lastInsertRowid;
  const b2 = db.run("INSERT INTO tender_bidders (uid, tender_id, contractor_id, bid_status) VALUES (?,?,?, 'technically_qualified')", [uuid(), t, c2]).lastInsertRowid;
  db.run("INSERT INTO financial_bids (uid, tender_id, bidder_id, total_amount_minor) VALUES (?,?,?,?)", [uuid(), t, b1, 120000000]);
  db.run("INSERT INTO financial_bids (uid, tender_id, bidder_id, total_amount_minor) VALUES (?,?,?,?)", [uuid(), t, b2, 95000000]);
  const ranked = financial.rankFinancialBids(t);
  assert.strictEqual(ranked[0].bidder_id, b2); // ₹9.5L is L1
  assert.strictEqual(ranked[0].rank, 1);
  assert.strictEqual(ranked[1].bidder_id, b1);
});
