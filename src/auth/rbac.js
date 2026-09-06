'use strict';

const db = require('../db/database');
const { forbidden, unauthorized } = require('../util/errors');

const _permissionCache = new Map(); // userId -> Set(permission codes)

function clearCache(userId) {
  _permissionCache.delete(userId);
}

function clearAllCache() {
  _permissionCache.clear();
}

/** Collect permission codes for a user (global admins get everything). */
function getPermissionsForUser(userId) {
  if (_permissionCache.has(userId)) return _permissionCache.get(userId);
  const user = db.get('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [userId]);
  const perms = new Set();
  if (!user) {
    _permissionCache.set(userId, perms);
    return perms;
  }
  if (user.is_global_admin) {
    for (const r of db.all('SELECT code FROM permissions')) perms.add(r.code);
  } else {
    const rows = db.all(
      `SELECT DISTINCT p.code
         FROM user_roles ur
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = ?`,
      [userId]
    );
    for (const r of rows) perms.add(r.code);
  }
  _permissionCache.set(userId, perms);
  return perms;
}

function getRolesForUser(userId) {
  return db.all(
    `SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?`,
    [userId]
  );
}

function userHasPermission(userId, code) {
  return getPermissionsForUser(userId).has(code);
}

/**
 * Express middleware: requires an authenticated user.
 */
function requireAuth(req, res, next) {
  if (!req.session || !req.session.userId) {
    return next(unauthorized());
  }
  const user = db.get('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [req.session.userId]);
  if (!user || !user.is_active) {
    req.session.destroy(() => {});
    return next(unauthorized('Session is no longer valid'));
  }
  if (user.locked_until && new Date(user.locked_until) > new Date()) {
    return next(forbidden('Account is temporarily locked due to repeated failed sign-ins'));
  }
  req.user = user;
  req.userRoles = getRolesForUser(user.id);
  req.userPermissions = getPermissionsForUser(user.id);
  next();
}

/** Middleware factory: require a permission. */
function requirePermission(code) {
  return (req, res, next) => {
    if (!req.user) return next(unauthorized());
    if (!req.userPermissions.has(code) && !req.user.is_global_admin) {
      return next(forbidden(`Missing permission: ${code}`));
    }
    next();
  };
}

/** Middleware factory: require any of the given permissions. */
function requireAnyPermission(...codes) {
  return (req, res, next) => {
    if (!req.user) return next(unauthorized());
    const ok = codes.some((c) => req.userPermissions.has(c));
    if (!ok && !req.user.is_global_admin) return next(forbidden());
    next();
  };
}

/** Resolve the user's effective panchayat scope. */
function userPanchayatId(req) {
  if (!req.user) return null;
  if (req.user.is_global_admin) return null; // null = all panchayats
  return req.user.panchayat_id || null;
}

module.exports = {
  getPermissionsForUser,
  getRolesForUser,
  userHasPermission,
  clearCache,
  clearAllCache,
  requireAuth,
  requirePermission,
  requireAnyPermission,
  userPanchayatId,
};
