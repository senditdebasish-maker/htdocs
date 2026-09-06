'use strict';

// Test setup helper. Each test file runs in its own Node process, so setting
// environment before requiring config is safe.
const fs = require('fs');
const os = require('os');
const path = require('path');

process.env.NODE_ENV = 'test';
process.env.DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'gp-test-'));
process.env.SESSION_SECRET = 'test-secret';
process.env.RATE_LIMIT_ENABLED = 'false';

const db = require('../../src/db/database');
const migrations = require('../../src/db/migrations');
const seed = require('../../src/db/seed');
const { createApp } = require('../../src/app');
const { hashPassword } = require('../../src/auth/passwords');
const { uuid } = require('../../src/util/uuid');

/** Fresh in-memory DB + app. */
function freshApp() {
  db.closeDb();
  db.setDb(db.openDatabase(':memory:'));
  db.migrate(migrations);
  seed.seedAll();
  return createApp();
}

/** Create a user with the given role codes and return { user, password }. */
function createUser(roleCodes = [], { email, name } = {}) {
  const password = 'Test@12345';
  const r = db.run(
    'INSERT INTO users (uid, email, password_hash, name, is_global_admin) VALUES (?,?,?,?,0)',
    [uuid(), email || `${Math.random().toString(36).slice(2)}@test.local`, hashPassword(password), name || 'Test User']
  );
  for (const code of roleCodes) {
    const role = db.get('SELECT id FROM roles WHERE code = ?', [code]);
    if (role) db.run('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [r.lastInsertRowid, role.id]);
  }
  return { id: Number(r.lastInsertRowid), email: email, password };
}

async function login(app, email, password) {
  const supertest = require('supertest');
  const agent = supertest.agent(app);
  const res = await agent.post('/api/auth/login').send({ email, password });
  return agent;
}

/** Minimal async login agent for the seeded global admin. */
async function adminAgent(app) {
  const supertest = require('supertest');
  const agent = supertest.agent(app);
  await agent.post('/api/auth/login').send({ email: 'admin@panchayat.local', password: 'ChangeMe@12345' });
  return agent;
}

module.exports = { freshApp, createUser, login, adminAgent, db };
