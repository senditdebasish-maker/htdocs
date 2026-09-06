'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { forbidden, badRequest, conflict } = require('../util/errors');
const audit = require('./auditService');
const notifications = require('./notificationService');

/**
 * Configurable approval workflow engine.
 *
 * Each entity type has a default step chain (editable in settings). Actions
 * are: approve | reject | return | clarification | cancel. Every action is
 * recorded in audit_logs and approval_steps. Permissions are enforced here
 * (server-side) via role requirements on each step.
 */

const DEFAULT_WORKFLOWS = {
  tender: {
    steps: [
      { name: 'Technical Verification', requiredRole: 'technical_officer' },
      { name: 'Secretary Approval', requiredRole: 'panchayat_secretary' },
      { name: 'Pradhan Approval', requiredRole: 'pradhan' },
      { name: 'Tender Committee', requiredRole: 'tender_committee' },
      { name: 'Competent Authority', requiredRole: 'pradhan' },
    ],
  },
  bill: {
    steps: [
      { name: 'Submitted', requiredRole: null },
      { name: 'Technical Check', requiredRole: 'technical_officer' },
      { name: 'Accounts Certification', requiredRole: 'accounts_officer' },
      { name: 'Approval', requiredRole: 'pradhan' },
    ],
  },
  corrigendum: {
    steps: [
      { name: 'Secretary Approval', requiredRole: 'panchayat_secretary' },
      { name: 'Competent Authority', requiredRole: 'pradhan' },
    ],
  },
};

function getWorkflowConfig(entityType) {
  const stored = db.get("SELECT value FROM settings WHERE key = 'workflow.' || ?", [entityType]);
  if (stored && stored.value) {
    try { return JSON.parse(stored.value); } catch (_) { /* fall through */ }
  }
  return DEFAULT_WORKFLOWS[entityType] || { steps: [] };
}

function init(entityType, entityId, opts = {}) {
  const config = opts.config || getWorkflowConfig(entityType);
  const existing = db.get('SELECT COUNT(*) c FROM approval_steps WHERE entity_type = ? AND entity_id = ?', [entityType, entityId]);
  if (existing.c > 0 && !opts.reset) return get(entityType, entityId);
  if (opts.reset) db.run('DELETE FROM approval_steps WHERE entity_type = ? AND entity_id = ?', [entityType, entityId]);
  const ins = db.prepare(
    `INSERT INTO approval_steps (uid, entity_type, entity_id, step_order, step_name, required_role, status)
     VALUES (?,?,?,?,?,?,?)`
  );
  config.steps.forEach((s, i) => {
    ins.run(uuid(), entityType, entityId, i + 1, s.name, s.requiredRole, i === 0 ? 'in_progress' : 'pending');
  });
  return get(entityType, entityId);
}

function get(entityType, entityId) {
  return db.all(
    'SELECT * FROM approval_steps WHERE entity_type = ? AND entity_id = ? ORDER BY step_order',
    [entityType, entityId]
  );
}

function currentStep(entityType, entityId) {
  const steps = get(entityType, entityId);
  return steps.find((s) => s.status === 'in_progress') || steps.find((s) => s.status === 'pending') || null;
}

function allApproved(steps) {
  return steps.length > 0 && steps.every((s) => s.status === 'approved' || s.status === 'skipped');
}

function actorCanAct(actor, requiredRole) {
  if (!actor) return false;
  if (actor.is_global_admin) return true;
  if (!requiredRole) return true; // no specific role required
  const roles = db.all('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [actor.id]);
  return roles.some((r) => r.code === requiredRole);
}

/**
 * Perform a workflow action on the current step.
 * Returns { step, done, steps }.
 */
function act(entityType, entityId, { action, remarks = null, actor, onComplete = null }) {
  if (!['approve', 'reject', 'return', 'clarification', 'cancel'].includes(action)) {
    throw badRequest('Invalid workflow action');
  }
  const steps = get(entityType, entityId);
  const step = currentStep(entityType, entityId);
  if (!step) throw badRequest('No pending workflow step');

  if (!actorCanAct(actor, step.required_role)) {
    throw forbidden(`Step "${step.step_name}" requires the "${step.required_role}" role`);
  }

  const actorName = actor ? actor.name : null;
  const actorRole = actor && actor.primaryRole ? actor.primaryRole : null;

  // Map the user-facing action to a step status that satisfies the CHECK constraint.
  const STATUS_BY_ACTION = { approve: 'approved', reject: 'rejected', return: 'returned', clarification: 'clarification', cancel: 'cancelled' };
  const stepStatus = STATUS_BY_ACTION[action];

  db.run(
    `UPDATE approval_steps SET status = ?, actor_id = ?, action = ?, remarks = ?, acted_at = CURRENT_TIMESTAMP
      WHERE id = ?`,
    [stepStatus, actor ? actor.id : null, action, remarks, step.id]
  );

  audit.record({
    actor,
    action: `workflow.${action}`,
    entityType,
    entityId,
    entityLabel: `step ${step.step_order}: ${step.step_name}`,
    reason: remarks,
  });

  let done = false;
  if (action === 'approve') {
    const next = steps.find((s) => s.step_order > step.step_order && s.status === 'pending');
    if (next) {
      db.run("UPDATE approval_steps SET status = 'in_progress' WHERE id = ?", [next.id]);
      if (next.required_role) {
        notifications.notify({
          roleCodes: [next.required_role],
          type: 'approval_pending',
          title: `Approval required: ${entityType} #${entityId}`,
          body: `Step "${next.step_name}" is awaiting your action.`,
          entityType,
          entityId,
          severity: 'info',
        });
      }
    } else {
      done = true;
    }
  }

  const after = get(entityType, entityId);
  if (done && onComplete) onComplete(actor);
  return { step: db.get('SELECT * FROM approval_steps WHERE id = ?', [step.id]), done, steps: after };
}

module.exports = { getWorkflowConfig, init, get, currentStep, allApproved, act, actorCanAct };
