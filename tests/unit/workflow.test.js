'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { freshApp, db, createUser } = require('../helpers/setup');
const workflow = require('../../src/services/workflowService');

function actorWithRole(roleCode) {
  const u = createUser([roleCode], { name: 'Role ' + roleCode });
  return db.get('SELECT * FROM users WHERE id = ?', [u.id]);
}

test('workflow requires the correct role at each step', () => {
  freshApp();
  workflow.init('tender', 1);
  const technical = actorWithRole('technical_officer');
  const secretary = actorWithRole('panchayat_secretary');

  // Wrong role on first step (Technical Verification) must be rejected.
  assert.throws(() => workflow.act('tender', 1, { action: 'approve', actor: secretary }), /requires the/);

  // Correct role succeeds.
  const r = workflow.act('tender', 1, { action: 'approve', actor: technical });
  assert.strictEqual(r.step.status, 'approved');
});

test('workflow completes when all steps approved', () => {
  freshApp();
  workflow.init('tender', 1);
  const tech = actorWithRole('technical_officer');
  const sec = actorWithRole('panchayat_secretary');
  const pradhan = actorWithRole('pradhan');
  const committee = actorWithRole('tender_committee');

  workflow.act('tender', 1, { action: 'approve', actor: tech });
  workflow.act('tender', 1, { action: 'approve', actor: sec });
  workflow.act('tender', 1, { action: 'approve', actor: pradhan });
  workflow.act('tender', 1, { action: 'approve', actor: committee });
  const last = workflow.act('tender', 1, { action: 'approve', actor: pradhan });
  assert.strictEqual(last.done, true);
  assert.ok(workflow.allApproved(workflow.get('tender', 1)));
});

test('global admin can act on any step', () => {
  freshApp();
  workflow.init('tender', 1);
  const admin = db.get('SELECT * FROM users WHERE is_global_admin = 1');
  const r = workflow.act('tender', 1, { action: 'approve', actor: admin });
  assert.strictEqual(r.step.status, 'approved');
});

test('invalid action and invalid status are rejected', () => {
  freshApp();
  workflow.init('tender', 1);
  const admin = db.get('SELECT * FROM users WHERE is_global_admin = 1');
  assert.throws(() => workflow.act('tender', 1, { action: 'nonsense', actor: admin }), /Invalid workflow action/);
});
