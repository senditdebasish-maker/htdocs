'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { freshApp, adminAgent } = require('../helpers/setup');

test('complete tender lifecycle: create → approve → NIT → publish → bid → evaluate → award → work order → bill → payment → completion', async (t) => {
  const app = freshApp();
  const agent = await adminAgent(app);
  const post = (url, body = {}) => agent.post(url).send(body);
  const put = (url, body = {}) => agent.put(url).send(body);

  // Contractors
  const c1 = (await post('/api/contractors', { legal_name: 'ABC Constructions' })).body.data.id;
  const c2 = (await post('/api/contractors', { legal_name: 'XYZ Infra' })).body.data.id;

  // Project
  const fy = (await agent.get('/api/financial-years')).body.data.current.id;
  const p = (await post('/api/projects', { fy_id: fy, work_name: 'CC Road Work', estimate_amount_minor: 100000000, sanctioned_amount_minor: 115000000 })).body.data.id;

  // Tender
  const tender = (await post('/api/tenders', { fy_id: fy, procurement_category: 'works', tender_type: 'open', project_id: p, work_name: 'CC Road Work', scheme_id: 1, fund_id: 1 })).body.data;
  assert.match(tender.tender_number, /GP\/NIT\/\d{4}-\d{2}\/\d{3}/);

  await put(`/api/tenders/${tender.id}`, {
    admin_approval_no: 'AA-1', admin_approval_date: '2026-06-01', tech_sanction_no: 'TS-1', tech_sanction_date: '2026-06-02',
    estimated_cost_minor: 100000000, tender_value_minor: 100000000, emd_minor: 2000000,
    publication_date: '2026-07-01', bid_close_date: '2026-07-15', technical_open_date: '2026-07-16',
  });

  await post(`/api/tenders/${tender.id}/boq`, { items: [{ item_no: '1', description: 'Earthwork', unit: 'cum', quantity: 100, estimated_rate_minor: 25000 }] });

  // Compliance
  const comp = (await post(`/api/tenders/${tender.id}/compliance`)).body.data;
  assert.strictEqual(comp.summary.blocking, 0);

  // Submit + approve workflow (global admin)
  await post(`/api/tenders/${tender.id}/submit`);
  for (let i = 0; i < 5; i++) await post(`/api/tenders/${tender.id}/workflow`, { action: 'approve', remarks: 'ok' });
  assert.strictEqual((await agent.get(`/api/tenders/${tender.id}`)).body.data.tender.status, 'approved');

  // NIT + publish + bidding
  const nit = (await post(`/api/tenders/${tender.id}/nit`)).body.data;
  assert.ok(nit.document.id);
  await post(`/api/tenders/${tender.id}/publish`);
  await post(`/api/tenders/${tender.id}/bidding`);

  // Bidders
  const b1 = (await post(`/api/tenders/${tender.id}/bidders`, { contractor_id: c1 })).body.data.id;
  const b2 = (await post(`/api/tenders/${tender.id}/bidders`, { contractor_id: c2 })).body.data.id;
  await post(`/api/tenders/${tender.id}/close-bids`);
  await post(`/api/tenders/${tender.id}/technical-open`);

  // Technical evaluation
  const k1 = (await post(`/api/tenders/${tender.id}/criteria`, { code: 'REG', criterion: 'Registration' })).body.data.id;
  const k2 = (await post(`/api/tenders/${tender.id}/criteria`, { code: 'EXP', criterion: 'Experience' })).body.data.id;
  await post(`/api/tenders/${tender.id}/evaluations`, { bidder_id: b1, criterion_id: k1, result: 'pass' });
  await post(`/api/tenders/${tender.id}/evaluations`, { bidder_id: b1, criterion_id: k2, result: 'pass' });
  await post(`/api/tenders/${tender.id}/evaluations`, { bidder_id: b2, criterion_id: k1, result: 'pass' });
  await post(`/api/tenders/${tender.id}/evaluations`, { bidder_id: b2, criterion_id: k2, result: 'fail' });
  await post(`/api/tenders/${tender.id}/finalize-technical`, { rejectionReasons: { [b2]: 'No experience' } });
  assert.strictEqual((await agent.get(`/api/tenders/${tender.id}`)).body.data.tender.status, 'financial_evaluation');

  // Financial bid + ranking
  await post(`/api/tenders/${tender.id}/financial-bids`, { bidder_id: b1, totalAmountMinor: 95000000 });
  const ranks = (await post(`/api/tenders/${tender.id}/rank`)).body.data.rankings;
  assert.strictEqual(ranks[0].rank, 1);

  // Award → LOA → agreement → work order
  const award = (await post('/api/awards/recommend', { tender_id: tender.id, contractor_id: c1, awarded_amount_minor: 95000000 })).body.data;
  await post(`/api/awards/${award.id}/approve`);
  const loa = (await post(`/api/awards/${award.id}/loa`)).body.data;
  assert.match(loa.award.loa_number, /LOA\//);
  const agreement = (await post(`/api/awards/${award.id}/agreement`)).body.data;
  assert.match(agreement.agreement_number, /AGR\//);
  const wo = (await post(`/api/awards/${award.id}/work-order`)).body.data;
  assert.match(wo.work_order_number, /WO\//);

  // Execution → measurement → bill → payment → completion
  const m = (await post(`/api/projects/${p}/measurements`, {})).body.data;
  const boqItem = (await agent.get(`/api/tenders/${tender.id}/boq`)).body.data.items[0].id;
  await post(`/api/measurements/${m.id}/items`, { boqItemId: boqItem, itemNo: '1', description: 'Earthwork', unit: 'cum', currentQuantity: 40, rateMinor: 25000 });

  const bill = (await post(`/api/projects/${p}/bills`, {})).body.data;
  await put(`/api/bills/${bill.id}`, { grossWorkValueMinor: 1000000, retentionPct: 5, deductionsMinor: 0, recoveriesMinor: 0 });
  assert.strictEqual((await agent.get(`/api/projects/${p}/bills`)).body.data.rows[0].net_payable_minor, 950000);
  await post(`/api/bills/${bill.id}/submit`);
  for (let i = 0; i < 4; i++) await post(`/api/bills/${bill.id}/workflow`, { action: 'approve', remarks: 'ok' });
  const payment = (await post(`/api/bills/${bill.id}/payments`, { netAmountMinor: 950000 })).body.data;
  assert.match(payment.voucher_no, /PVR\//);

  await post(`/api/projects/${p}/completion`, { status: 'closed' });
  const final = (await agent.get(`/api/projects/${p}/completion`)).body.data;
  assert.strictEqual(final.status, 'closed');

  // Reconciliation reflects the flow.
  const recon = (await agent.get('/api/reports/reconciliation')).body.data;
  const row = recon.find((r) => r.tender_number === tender.tender_number);
  assert.strictEqual(row.paid_minor, 950000);
  assert.strictEqual(row.awarded_minor, 95000000);
});
