'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { json } = require('../db/database');

/**
 * Audit trail service. Every important action must create an audit event.
 * Audit records are append-only: the service only inserts, never updates or
 * deletes, and no ordinary role can edit audit rows.
 */
function record({
  actor = null, // req.user object or null for system
  action,
  entityType,
  entityId = null,
  entityLabel = null,
  oldValue = null,
  newValue = null,
  reason = null,
  ipAddress = null,
  userAgent = null,
  requestId = null,
  panchayatId = null,
}) {
  try {
    const actorName = actor ? actor.name : null;
    const actorRole = actor && actor.primaryRole ? actor.primaryRole : null;
    db.run(
      `INSERT INTO audit_logs
        (uid, actor_id, actor_name, actor_role, panchayat_id, action, entity_type, entity_id, entity_label,
         old_value, new_value, reason, ip_address, user_agent, request_id)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
      [
        uuid(),
        actor ? actor.id : null,
        actorName,
        actorRole,
        panchayatId,
        action,
        entityType,
        entityId,
        entityLabel,
        oldValue === undefined || oldValue === null ? null : (typeof oldValue === 'string' ? oldValue : json(oldValue)),
        newValue === undefined || newValue === null ? null : (typeof newValue === 'string' ? newValue : json(newValue)),
        reason || null,
        ipAddress || null,
        userAgent || null,
        requestId || null,
      ]
    );
  } catch (err) {
    // Audit failure must never break the primary request, but it should be surfaced in logs.
    // eslint-disable-next-line no-console
    console.error('[audit] failed to record event:', err.message);
  }
}

/** Express middleware that sets requestId and helpers on req. */
function auditMiddleware(req, res, next) {
  req.requestId = req.headers['x-request-id'] || uuid();
  res.setHeader('X-Request-Id', req.requestId);
  req.audit = (opts) =>
    record({
      actor: req.user,
      ipAddress: req.ip,
      userAgent: req.get('user-agent'),
      requestId: req.requestId,
      panchayatId: req.user ? req.user.panchayat_id : null,
      ...opts,
    });
  next();
}

module.exports = { record, auditMiddleware };
