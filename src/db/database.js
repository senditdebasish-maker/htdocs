'use strict';

const path = require('path');
const fs = require('fs');
const { DatabaseSync } = require('node:sqlite');
const config = require('../config');

/**
 * Thin wrapper over node:sqlite with:
 *  - foreign keys always on
 *  - WAL journaling
 *  - JSON helpers
 *  - transaction helpers
 *  - migration tracking
 */

let db = null;

function openDatabase(filePath = config.databasePath) {
  if (filePath !== ':memory:') {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
  }
  const d = new DatabaseSync(filePath);
  d.exec('PRAGMA foreign_keys = ON;');
  d.exec('PRAGMA journal_mode = WAL;');
  d.exec('PRAGMA busy_timeout = 5000;');
  return d;
}

function getDb() {
  if (!db) db = openDatabase();
  return db;
}

function closeDb() {
  if (db) {
    try { db.close(); } catch (_) { /* ignore */ }
    db = null;
  }
}

/** Replace a db instance (used by tests). */
function setDb(instance) {
  db = instance;
}

/** Run a migration list of {version, name, sql[]}. */
function migrate(migrations) {
  const d = getDb();
  d.exec(
    `CREATE TABLE IF NOT EXISTS schema_migrations (
       version INTEGER PRIMARY KEY,
       name TEXT NOT NULL,
       applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
     );`
  );
  const applied = new Set(d.prepare('SELECT version FROM schema_migrations').all().map((r) => r.version));
  const sorted = [...migrations].sort((a, b) => a.version - b.version);
  for (const m of sorted) {
    if (applied.has(m.version)) continue;
    d.exec('BEGIN');
    try {
      for (const sql of m.sql) d.exec(sql);
      d.prepare('INSERT INTO schema_migrations(version, name) VALUES(?, ?)').run(m.version, m.name);
      d.exec('COMMIT');
    } catch (err) {
      d.exec('ROLLBACK');
      throw new Error(`Migration v${m.version} (${m.name}) failed: ${err.message}`);
    }
  }
}

function toPlain(row) {
  if (row === null || row === undefined) return row;
  if (Array.isArray(row)) return row.map(toPlain);
  // node:sqlite returns rows with null prototype; convert to plain objects.
  const out = {};
  for (const k of Object.keys(row)) out[k] = row[k];
  return out;
}

function all(sql, params = []) {
  return getDb().prepare(sql).all(...params).map(toPlain);
}

function get(sql, params = []) {
  return toPlain(getDb().prepare(sql).get(...params));
}

function run(sql, params = []) {
  const info = getDb().prepare(sql).run(...params);
  return { lastInsertRowid: Number(info.lastInsertRowid), changes: Number(info.changes) };
}

function prepare(sql) {
  return getDb().prepare(sql);
}

/** Execute fn within a transaction; rethrow after rollback. */
function tx(fn) {
  const d = getDb();
  d.exec('BEGIN');
  try {
    const result = fn(d);
    d.exec('COMMIT');
    return result;
  } catch (err) {
    try { d.exec('ROLLBACK'); } catch (_) { /* ignore */ }
    throw err;
  }
}

function json(v) {
  return v === undefined || v === null ? null : JSON.stringify(v);
}

function parseJson(v, fallback = null) {
  if (v === null || v === undefined || v === '') return fallback;
  try {
    return JSON.parse(v);
  } catch (_) {
    return fallback;
  }
}

module.exports = { openDatabase, getDb, closeDb, setDb, migrate, all, get, run, prepare, tx, json, parseJson };
