'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');

/**
 * In-app notification service.
 *
 * Channels: today only 'inapp'. The schema and API are channel-agnostic so
 * email/SMS providers can be added later without changing call sites.
 */
function notify({
  userId = null,
  roleCodes = [], // notify all users holding any of these roles
  type,
  title,
  body = null,
  entityType = null,
  entityId = null,
  link = null,
  severity = 'info',
}) {
  const recipients = new Set();
  if (userId) recipients.add(userId);
  for (const roleCode of roleCodes) {
    const rows = db.all(
      `SELECT DISTINCT u.id
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
        WHERE r.code = ? AND u.is_active = 1 AND u.deleted_at IS NULL`,
      [roleCode]
    );
    for (const r of rows) recipients.add(r.id);
  }
  const insert = db.prepare(
    `INSERT INTO notifications (uid, user_id, type, title, body, entity_type, entity_id, link, severity)
     VALUES (?,?,?,?,?,?,?,?,?)`
  );
  for (const uid of recipients) {
    insert.run(uuid(), uid, type, title, body, entityType, entityId, link, severity);
  }
}

function unreadCount(userId) {
  const r = db.get('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0', [userId]);
  return r ? r.c : 0;
}

function listForUser(userId, { limit = 30, offset = 0, unreadOnly = false } = {}) {
  return db.all(
    `SELECT * FROM notifications
      WHERE user_id = ? ${unreadOnly ? 'AND is_read = 0' : ''}
      ORDER BY created_at DESC LIMIT ? OFFSET ?`,
    [userId, limit, offset]
  );
}

function markRead(userId, ids) {
  if (!ids || !ids.length) return 0;
  const placeholders = ids.map(() => '?').join(',');
  return db.run(
    `UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN (${placeholders})`,
    [userId, ...ids]
  ).changes;
}

module.exports = { notify, unreadCount, listForUser, markRead };
