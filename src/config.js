'use strict';

const path = require('path');
const fs = require('fs');

// Load a simple .env file if present (no external dependency).
function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) return;
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (!(key in process.env)) process.env[key] = value;
  }
}

const ROOT = path.resolve(__dirname, '..');
loadDotEnv(path.join(ROOT, '.env'));

function envInt(name, def) {
  const v = process.env[name];
  if (v === undefined || v === '') return def;
  const n = parseInt(v, 10);
  return Number.isNaN(n) ? def : n;
}

function envBool(name, def) {
  const v = process.env[name];
  if (v === undefined || v === '') return def;
  return ['1', 'true', 'yes', 'on'].includes(String(v).toLowerCase());
}

const config = {
  root: ROOT,
  env: process.env.NODE_ENV || 'development',
  isProd: (process.env.NODE_ENV || 'development') === 'production',
  isTest: (process.env.NODE_ENV || 'development') === 'test',

  port: envInt('PORT', 3000),
  host: process.env.HOST || '0.0.0.0',
  baseUrl: (process.env.BASE_URL || `http://localhost:${envInt('PORT', 3000)}`).replace(/\/$/, ''),

  sessionSecret: process.env.SESSION_SECRET || 'dev-insecure-session-secret-change-me',
  sessionCookieSecure: envBool('SESSION_COOKIE_SECURE', false),
  sessionCookieSameSite: process.env.SESSION_COOKIE_SAME_SITE || 'lax',
  sessionMaxAgeMs: envInt('SESSION_MAX_AGE_MS', 8 * 60 * 60 * 1000),

  dataDir: path.resolve(ROOT, process.env.DATA_DIR || 'data'),
  // DATABASE_PATH (if set) overrides the location; otherwise it lives under DATA_DIR.
  databasePath: process.env.DATABASE_PATH
    ? path.resolve(ROOT, process.env.DATABASE_PATH)
    : path.join(path.resolve(ROOT, process.env.DATA_DIR || 'data'), 'app.db'),
  uploadsDir: () => path.join(config.dataDir, 'uploads'),
  generatedDir: () => path.join(config.dataDir, 'generated'),
  backupsDir: () => path.join(config.dataDir, 'backups'),

  maxUploadBytes: envInt('MAX_UPLOAD_BYTES', 25 * 1024 * 1024),
  trustProxy: envInt('TRUST_PROXY', 1),
  rateLimitEnabled: envBool('RATE_LIMIT_ENABLED', true),

  seedAdminEmail: process.env.SEED_ADMIN_EMAIL || 'admin@panchayat.local',
  seedAdminPassword: process.env.SEED_ADMIN_PASSWORD || 'ChangeMe@12345',

  // System / legal-integrity labels.
  systemName: 'Gram Panchayat Procurement & Work Management Portal',
  systemShortName: 'GP Portal',
};

// Ensure data directories exist.
for (const dir of [config.dataDir, config.uploadsDir(), config.generatedDir(), config.backupsDir()]) {
  fs.mkdirSync(dir, { recursive: true });
}

module.exports = config;
