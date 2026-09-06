'use strict';

const test = require('node:test');
const assert = require('node:assert');
const supertest = require('supertest');
const { freshApp, createUser, db } = require('../helpers/setup');

async function login(app, email, password) {
  const agent = supertest.agent(app);
  await agent.post('/api/auth/login').send({ email, password });
  return agent;
}

test('unauthenticated requests are rejected', async () => {
  const app = freshApp();
  const res = await supertest(app).get('/api/tenders');
  assert.strictEqual(res.status, 401);
  const res2 = await supertest(app).post('/api/tenders').send({ fy_id: 1 });
  assert.strictEqual(res2.status, 401);
});

test('wrong password does not authenticate', async () => {
  const app = freshApp();
  const res = await supertest(app).post('/api/auth/login').send({ email: 'admin@panchayat.local', password: 'wrong' });
  assert.strictEqual(res.status, 401);
});

test('a data-entry user cannot approve a tender (privilege escalation blocked)', async () => {
  const app = freshApp();
  const u = createUser(['data_entry'], { email: 'de@test.local' });
  const agent = await login(app, u.email, u.password);

  // Create a tender as admin and move it to under_approval.
  const admin = await login(app, 'admin@panchayat.local', 'ChangeMe@12345');
  const tender = (await admin.post('/api/tenders').send({ fy_id: 1, procurement_category: 'works', tender_type: 'open', work_name: 'W' })).body.data;
  await admin.put(`/api/tenders/${tender.id}`).send({ admin_approval_no: 'A', tech_sanction_no: 'T', estimated_cost_minor: 100000 });
  await admin.post(`/api/tenders/${tender.id}/boq`).send({ items: [{ item_no: '1', description: 'x', quantity: 1, estimated_rate_minor: 1000 }] });
  await admin.post(`/api/tenders/${tender.id}/submit`);

  const res = await agent.post(`/api/tenders/${tender.id}/workflow`).send({ action: 'approve' });
  assert.strictEqual(res.status, 403);
});

test('locked (published) tender cannot be edited', async () => {
  const app = freshApp();
  const admin = await login(app, 'admin@panchayat.local', 'ChangeMe@12345');
  const tender = (await admin.post('/api/tenders').send({ fy_id: 1, procurement_category: 'works', tender_type: 'open', work_name: 'W' })).body.data;
  await admin.put(`/api/tenders/${tender.id}`).send({ admin_approval_no: 'A', tech_sanction_no: 'T', estimated_cost_minor: 100000 });
  await admin.post(`/api/tenders/${tender.id}/boq`).send({ items: [{ item_no: '1', description: 'x', quantity: 1, estimated_rate_minor: 1000 }] });
  await admin.post(`/api/tenders/${tender.id}/submit`);
  for (let i = 0; i < 5; i++) await admin.post(`/api/tenders/${tender.id}/workflow`).send({ action: 'approve' });
  await admin.post(`/api/tenders/${tender.id}/publish`);

  const res = await admin.put(`/api/tenders/${tender.id}`).send({ estimated_cost_minor: 1 });
  assert.strictEqual(res.status, 409);
  assert.match(res.body.error.message, /locked/i);
});

test('duplicate tender numbers are prevented at DB level', async () => {
  const app = freshApp();
  const admin = await login(app, 'admin@panchayat.local', 'ChangeMe@12345');
  const t1 = (await admin.post('/api/tenders').send({ fy_id: 1, procurement_category: 'works', tender_type: 'open', work_name: 'W' })).body.data;
  // Direct DB duplicate insert must fail on the UNIQUE constraint.
  assert.throws(() => db.run(
    "INSERT INTO tenders (uid, fy_id, tender_number, tender_type, procurement_category, title, status) VALUES ('dup1', 1, ?, 'open', 'works', 'Dup', 'draft')",
    [t1.tender_number]
  ));
});

test('payment cannot exceed certified amount', async () => {
  const app = freshApp();
  const admin = await login(app, 'admin@panchayat.local', 'ChangeMe@12345');
  const fy = (await admin.get('/api/financial-years')).body.data.current.id;
  const p = (await admin.post('/api/projects').send({ fy_id: fy, work_name: 'W' })).body.data.id;
  const bill = (await admin.post(`/api/projects/${p}/bills`).send({})).body.data;
  await admin.put(`/api/bills/${bill.id}`).send({ grossWorkValueMinor: 1000000, retentionPct: 0 });
  // Force bill to certified state so payment is possible.
  db.run("UPDATE bills SET status = 'certified' WHERE id = ?", [bill.id]);
  const over = await admin.post(`/api/bills/${bill.id}/payments`).send({ netAmountMinor: 2000000 });
  assert.strictEqual(over.status, 409);
});

test('expired-contractor document is flagged', () => {
  const app = freshApp();
  const adminId = db.get('SELECT id FROM users WHERE is_global_admin = 1').id;
  db.run("INSERT INTO contractors (uid, contractor_code, legal_name) VALUES ('x1', 'C/1', 'Old Co')");
  const c = db.get("SELECT id FROM contractors WHERE contractor_code = 'C/1'").id;
  db.run("INSERT INTO contractor_documents (uid, contractor_id, doc_type, doc_name, expiry_date, expiry_status) VALUES ('d1', ?, 'gst', 'GST', '2020-01-01', 'valid')", [c]);
  require('../../src/services/contractorService').refreshExpiryStatuses();
  const doc = db.get("SELECT expiry_status FROM contractor_documents WHERE contractor_id = ?", [c]);
  assert.strictEqual(doc.expiry_status, 'expired');
});

test('cancelled tender cannot be published (reactivation blocked)', async () => {
  const app = freshApp();
  const admin = await login(app, 'admin@panchayat.local', 'ChangeMe@12345');
  const tender = (await admin.post('/api/tenders').send({ fy_id: 1, procurement_category: 'works', tender_type: 'open', work_name: 'W' })).body.data;
  await admin.put(`/api/tenders/${tender.id}`).send({ admin_approval_no: 'A', tech_sanction_no: 'T', estimated_cost_minor: 100000 });
  await admin.post(`/api/tenders/${tender.id}/boq`).send({ items: [{ item_no: '1', description: 'x', quantity: 1, estimated_rate_minor: 1000 }] });
  await admin.post(`/api/tenders/${tender.id}/submit`);
  for (let i = 0; i < 5; i++) await admin.post(`/api/tenders/${tender.id}/workflow`).send({ action: 'approve' });
  await admin.post(`/api/tenders/${tender.id}/cancel`).send({ reason: 'test' });
  const res = await admin.post(`/api/tenders/${tender.id}/publish`);
  assert.strictEqual(res.status, 409);
});

test('public portal exposes no confidential bid data', async () => {
  const app = freshApp();
  const res = await supertest(app).get('/public');
  assert.strictEqual(res.status, 200);
  assert.ok(!/password|bank_account|total_amount_minor/.test(res.text));
});
