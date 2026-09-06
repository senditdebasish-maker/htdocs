'use strict';

const crypto = require('crypto');

function uuid() {
  return crypto.randomUUID();
}

/** Short verification code (for document QR / verification links). */
function verificationCode() {
  return crypto.randomBytes(6).toString('hex').toUpperCase();
}

module.exports = { uuid, verificationCode };
