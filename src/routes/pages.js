'use strict';

const express = require('express');
const path = require('path');
const router = express.Router();
const config = require('../config');
const db = require('../db/database');
const { requireAuth, requirePermission } = require('../auth/rbac');
const money = require('../util/money');
const dates = require('../util/dates');
const fyService = require('../services/fyService');
const notifications = require('../services/notificationService');

const viewsDir = path.join(__dirname, '..', 'views');

router.use((req, res, next) => {
  res.locals.config = config;
  res.locals.money = money;
  res.locals.dates = dates;
  res.locals.path = req.path;
  res.locals.flash = req.session.flash || null;
  if (req.user) {
    res.locals.unreadNotifications = notifications.unreadCount(req.user.id);
  }
  next();
});

const render = (res, view, extra = {}) => res.render(path.join(viewsDir, view), extra);

// ---------------------------- Public portal (no auth) ----------------------------
router.get('/public', (req, res) => {
  const fyId = req.query.fyId ? parseInt(req.query.fyId, 10) : null;
  const conds = ["t.status IN ('published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','closed') AND t.deleted_at IS NULL"];
  const params = [];
  if (fyId) { conds.push('t.fy_id = ?'); params.push(fyId); }
  const where = conds.join(' AND ');
  const tenders = db.all(
    `SELECT t.*, f.label fy_label FROM tenders t LEFT JOIN financial_years f ON f.id=t.fy_id
      WHERE ${where} ORDER BY t.publication_date DESC, t.id DESC LIMIT 100`,
    params
  );
  const fys = fyService.listFY();
  render(res, 'public', { tenders, fys, activeFy: fyId });
});

// ---------------------------- Auth pages ----------------------------
router.get('/login', (req, res) => {
  if (req.session && req.session.userId) return res.redirect('/');
  render(res, 'login');
});

router.get('/logout', (req, res) => {
  req.session.destroy(() => res.redirect('/login'));
});

// ---------------------------- Authenticated pages ----------------------------
router.get('/', requireAuth, (req, res) => res.redirect('/dashboard'));

router.get('/dashboard', requireAuth, requirePermission('report.view'), (req, res) => {
  render(res, 'dashboard');
});

router.get('/tenders', requireAuth, requirePermission('tender.view'), (req, res) => {
  render(res, 'tenders');
});

router.get('/tenders/new', requireAuth, requirePermission('tender.manage'), (req, res) => {
  render(res, 'tender-new');
});

router.get('/tenders/:id', requireAuth, requirePermission('tender.view'), (req, res) => {
  render(res, 'tender', { tenderId: parseInt(req.params.id, 10) });
});

router.get('/contractors', requireAuth, requirePermission('contractor.view'), (req, res) => {
  render(res, 'contractors');
});

router.get('/projects', requireAuth, requirePermission('project.view'), (req, res) => {
  render(res, 'projects');
});

router.get('/rules', requireAuth, requirePermission('rules.view'), (req, res) => {
  render(res, 'rules');
});

router.get('/compliance', requireAuth, (req, res) => {
  render(res, 'compliance');
});

router.get('/reports', requireAuth, requirePermission('report.view'), (req, res) => {
  render(res, 'reports');
});

router.get('/financial-years', requireAuth, requirePermission('fy.view'), (req, res) => {
  render(res, 'fiscal-years');
});

router.get('/settings', requireAuth, requirePermission('panchayat.view'), (req, res) => {
  render(res, 'settings');
});

router.get('/users', requireAuth, requirePermission('user.view'), (req, res) => {
  render(res, 'users');
});

router.get('/audit', requireAuth, requirePermission('audit.view'), (req, res) => {
  render(res, 'audit');
});

router.get('/notifications', requireAuth, (req, res) => {
  render(res, 'notifications');
});

module.exports = router;
