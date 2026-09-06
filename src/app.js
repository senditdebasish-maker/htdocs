'use strict';

const path = require('path');
const express = require('express');
const session = require('express-session');
const config = require('./config');
const SQLiteSessionStore = require('./sessionStore');
const { auditMiddleware } = require('./services/auditService');
const { requireAuth } = require('./auth/rbac');

function createApp() {
  const app = express();
  app.disable('x-powered-by');
  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, 'views'));
  if (config.trustProxy) app.set('trust proxy', config.trustProxy);

  // ---- Security headers ----
  app.use((req, res, next) => {
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('X-Frame-Options', 'SAMEORIGIN');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader(
      'Content-Security-Policy',
      "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
    );
    next();
  });

  // ---- Body parsing ----
  app.use(express.json({ limit: '2mb' }));
  app.use(express.urlencoded({ extended: true, limit: '2mb' }));

  // ---- Sessions ----
  app.use(
    session({
      store: new SQLiteSessionStore(),
      name: 'gpp.sid',
      secret: config.sessionSecret,
      resave: false,
      saveUninitialized: false,
      rolling: true,
      cookie: {
        httpOnly: true,
        sameSite: config.sessionCookieSameSite,
        secure: config.sessionCookieSecure,
        maxAge: config.sessionMaxAgeMs,
      },
    })
  );

  // ---- Request/audit correlation ----
  app.use(auditMiddleware);

  // ---- Simple in-memory rate limiter for auth ----
  if (config.rateLimitEnabled) {
    const attempts = new Map();
    app.use('/api/auth/login', (req, res, next) => {
      const key = req.ip || 'unknown';
      const now = Date.now();
      const entry = attempts.get(key) || { count: 0, reset: now + 15 * 60 * 1000 };
      if (now > entry.reset) entry.count = 0, entry.reset = now + 15 * 60 * 1000;
      entry.count += 1;
      attempts.set(key, entry);
      if (entry.count > 30) {
        return res.status(429).json({ error: { message: 'Too many attempts. Try again later.', code: 'RATE_LIMITED' } });
      }
      next();
    });
  }

  // ---- Static assets ----
  app.use('/assets', express.static(path.join(__dirname, '..', 'public')));

  // ---- Routes ----
  app.use('/api', require('./routes/api'));
  app.use(require('./routes/pages'));

  // ---- 404 ----
  app.use((req, res, next) => {
    if (req.path.startsWith('/api')) {
      return res.status(404).json({ error: { message: 'Not found', code: 'NOT_FOUND' } });
    }
    next();
  });

  // ---- Central error handler (never leaks stack traces to users) ----
  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    const status = err.status || 500;
    const requestId = req.requestId || 'n/a';
    // eslint-disable-next-line no-console
    if (status >= 500) console.error(`[error][${requestId}]`, err);
    if (req.path.startsWith('/api')) {
      return res.status(status).json({
        error: {
          message: status >= 500 ? 'An unexpected error occurred.' : err.message,
          code: err.code || 'ERROR',
          requestId,
          details: status < 500 && err.details ? err.details : undefined,
        },
      });
    }
    res.status(status);
    res.send(`<h1>${status}</h1><p>${status >= 500 ? 'An unexpected error occurred.' : escapeHtml(err.message)}</p><p>Request ID: ${escapeHtml(requestId)}</p>`);
  });

  return app;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

module.exports = { createApp };
