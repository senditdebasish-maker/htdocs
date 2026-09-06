'use strict';

const crypto = require('crypto');

// PBKDF2-HMAC-SHA256, 120k iterations, 32-byte salt. Constant-time compare.
const ITERATIONS = 120000;
const KEYLEN = 64;
const DIGEST = 'sha256';

function hashPassword(password) {
  const salt = crypto.randomBytes(32).toString('hex');
  const hash = crypto.pbkdf2Sync(password, salt, ITERATIONS, KEYLEN, DIGEST).toString('hex');
  return `pbkdf2$${ITERATIONS}$${salt}$${hash}`;
}

function verifyPassword(password, stored) {
  if (!stored || typeof stored !== 'string') return false;
  const parts = stored.split('$');
  if (parts[0] !== 'pbkdf2' || parts.length !== 4) return false;
  const iterations = parseInt(parts[1], 10);
  const salt = parts[2];
  const expected = parts[3];
  const hash = crypto.pbkdf2Sync(password, salt, iterations, KEYLEN, DIGEST).toString('hex');
  const a = Buffer.from(hash, 'hex');
  const b = Buffer.from(expected, 'hex');
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}

function passwordStrength(password) {
  if (!password || password.length < 8) return false;
  return /[A-Z]/.test(password) && /[a-z]/.test(password) && /[0-9]/.test(password);
}

module.exports = { hashPassword, verifyPassword, passwordStrength };
