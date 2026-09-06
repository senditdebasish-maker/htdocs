'use strict';

const express = require('express');
const multer = require('multer');
const router = express.Router();

const db = require('../db/database');
const { json, parseJson } = require('../db/database');
const { requireAuth, requirePermission, requireAnyPermission } = require('../auth/rbac');
const { hashPassword, verifyPassword, passwordStrength } = require('../auth/passwords');
const { uuid } = require('../util/uuid');
const dates = require('../util/dates');
const { badRequest, validation } = require('../util/errors');

const fyService = require('../services/fyService');
const numbering = require('../services/numberingService');
const complianceService = require('../services/complianceService');
const tenderService = require('../services/tenderService');
const contractorService = require('../services/contractorService');
const bidService = require('../services/bidService');
const awardService = require('../services/awardService');
const lifecycleService = require('../services/lifecycleService');
const financialService = require('../services/financialService');
const reportService = require('../services/reportService');
const documentService = require('../services/documentService');
const documentGen = require('../services/documentGeneration');
const audit = require('../services/auditService');
const notifications = require('../services/notificationService');
const workflowService = require('../services/workflowService');

const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);
const ok = (res, data) => res.json({ data });
const int = (v) => (v === null || v === undefined || v === '' ? null : parseInt(v, 10));
// Money convention: all API money fields are integer MINOR units.
const minor = (v) => (v === null || v === undefined || v === '' ? null : Math.round(Number(v) || 0));

// ---------------------------- Auth ----------------------------
router.post('/auth/login', wrap(async (req, res) => {
  const { email, password } = req.body || {};
  if (!email || !password) throw badRequest('Email and password are required');
  const user = db.get('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [String(email).trim().toLowerCase()]);
  if (!user || !verifyPassword(password, user.password_hash)) {
    audit.record({ action: 'auth.login_failed', entityType: 'user', entityLabel: email, ipAddress: req.ip, userAgent: req.get('user-agent'), requestId: req.requestId });
    throw new (require('../util/errors').AppError)(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
  }
  if (!user.is_active) throw new (require('../util/errors').AppError)(403, 'ACCOUNT_DISABLED', 'Account is disabled');
  if (user.locked_until && new Date(user.locked_until) > new Date()) {
    throw new (require('../util/errors').AppError)(403, 'ACCOUNT_LOCKED', 'Account temporarily locked');
  }
  db.run("UPDATE users SET last_login_at = CURRENT_TIMESTAMP, failed_login_count = 0, locked_until = NULL WHERE id = ?", [user.id]);
  // Regenerate session id on login to prevent session fixation.
  if (req.session.regenerate) {
    await new Promise((resolve, reject) => req.session.regenerate((err) => (err ? reject(err) : resolve())));
  }
  req.session.userId = user.id;
  audit.record({ actor: user, action: 'auth.login', entityType: 'user', entityId: user.id, entityLabel: user.email, ipAddress: req.ip, userAgent: req.get('user-agent'), requestId: req.requestId });
  const roles = db.all('SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [user.id]);
  ok(res, { user: { id: user.id, uid: user.uid, name: user.name, email: user.email, designation: user.designation, is_global_admin: user.is_global_admin, panchayat_id: user.panchayat_id }, roles: roles.map((r) => ({ code: r.code, name: r.name })) });
}));

router.post('/auth/logout', (req, res) => {
  req.session.destroy(() => {});
  ok(res, { success: true });
});

router.get('/auth/me', requireAuth, (req, res) => {
  const roles = db.all('SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [req.user.id]);
  ok(res, { user: { id: req.user.id, uid: req.user.uid, name: req.user.name, email: req.user.email, designation: req.user.designation, is_global_admin: req.user.is_global_admin, panchayat_id: req.user.panchayat_id }, roles: roles.map((r) => ({ code: r.code, name: r.name })), permissions: [...req.userPermissions] });
});

// ---------------------------- Dashboard & reports ----------------------------
router.get('/dashboard', requireAuth, requirePermission('report.view'), (req, res) => {
  ok(res, reportService.dashboard(int(req.query.fyId)));
});

router.get('/reports/tenders', requireAuth, requirePermission('report.view'), (req, res) => {
  ok(res, reportService.tenderRegister({
    fyId: int(req.query.fyId), status: req.query.status, tenderType: req.query.type,
    schemeId: int(req.query.schemeId), fundId: int(req.query.fundId), q: req.query.q,
    sort: req.query.sort, dir: req.query.dir, limit: int(req.query.limit) || 50, offset: int(req.query.offset) || 0,
  }));
});

router.get('/reports/bills', requireAuth, requirePermission('report.view'), (req, res) => {
  ok(res, reportService.billRegister({ fyId: int(req.query.fyId), status: req.query.status, limit: int(req.query.limit) || 50, offset: int(req.query.offset) || 0 }));
});

router.get('/reports/payments', requireAuth, requirePermission('report.view'), (req, res) => {
  ok(res, reportService.paymentRegister({ fyId: int(req.query.fyId), limit: int(req.query.limit) || 50, offset: int(req.query.offset) || 0 }));
});

router.get('/reports/dimension/:dim', requireAuth, requirePermission('report.view'), (req, res) => {
  ok(res, reportService.dimensionSummary(req.params.dim, int(req.query.fyId)));
});

router.get('/reports/reconciliation', requireAuth, requirePermission('report.view'), (req, res) => {
  ok(res, reportService.reconciliation(int(req.query.fyId)));
});

router.get('/compliance/center', requireAuth, requireAnyPermission('report.view', 'rules.view'), (req, res) => {
  ok(res, reportService.complianceCenter(int(req.query.fyId)));
});

// ---------------------------- Financial years ----------------------------
router.get('/financial-years', requireAuth, requirePermission('fy.view'), (req, res) => {
  ok(res, { rows: fyService.listFY(), current: fyService.getCurrentFY() });
});
router.post('/financial-years', requireAuth, requirePermission('fy.manage'), wrap(async (req, res) => {
  const f = fyService.createFY(req.body);
  audit.record({ actor: req.user, action: 'fy.create', entityType: 'financial_year', entityId: f.id, entityLabel: f.label });
  ok(res, f);
}));
router.post('/financial-years/:id/current', requireAuth, requirePermission('fy.manage'), wrap(async (req, res) => {
  ok(res, fyService.setCurrentFY(int(req.params.id)));
}));
router.post('/financial-years/:id/open', requireAuth, requirePermission('fy.manage'), wrap(async (req, res) => {
  ok(res, fyService.openFY(int(req.params.id)));
}));
router.post('/financial-years/:id/close', requireAuth, requirePermission('fy.manage'), wrap(async (req, res) => {
  ok(res, fyService.closeFY(int(req.params.id)));
}));

// ---------------------------- Panchayat ----------------------------
router.get('/panchayat', requireAuth, requirePermission('panchayat.view'), (req, res) => {
  const p = db.get('SELECT * FROM panchayats ORDER BY id LIMIT 1');
  ok(res, p || {});
});
router.put('/panchayat', requireAuth, requirePermission('panchayat.manage'), (req, res) => {
  const p = db.get('SELECT * FROM panchayats ORDER BY id LIMIT 1');
  const allowed = ['state', 'district', 'block', 'gram_panchayat', 'gp_code', 'office_address', 'pin', 'phone', 'email', 'pradhan', 'upa_pradhan', 'panchayat_secretary', 'technical_officer', 'accounts_officer', 'letterhead_html', 'document_header_html', 'document_footer_html', 'signature_config', 'seal_config'];
  const updates = [];
  const params = [];
  for (const k of allowed) if (k in req.body) { updates.push(`${k} = ?`); params.push(req.body[k]); }
  if (!updates.length) return ok(res, p);
  if (!p) {
    params.unshift('West Bengal');
    db.run(`INSERT INTO panchayats (uid, state, ${allowed.join(',')}) VALUES (?,?,${allowed.map(() => '?').join(',')})`, [uuid(), 'West Bengal', ...Object.keys(req.body).filter((k) => allowed.includes(k)).map((k) => req.body[k])]);
  } else {
    params.push(p.id);
    db.run(`UPDATE panchayats SET ${updates.join(', ')} WHERE id = ?`, params);
  }
  audit.record({ actor: req.user, action: 'panchayat.update', entityType: 'panchayat', entityId: p ? p.id : null });
  ok(res, db.get('SELECT * FROM panchayats ORDER BY id LIMIT 1'));
});

// ---------------------------- Schemes / Funds ----------------------------
router.get('/schemes', requireAuth, requirePermission('scheme.view'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM schemes ORDER BY name') });
});
router.post('/schemes', requireAuth, requirePermission('scheme.manage'), (req, res) => {
  if (!req.body.name) throw validation('Name is required');
  const r = db.run('INSERT INTO schemes (uid, name, code, description) VALUES (?,?,?,?)', [uuid(), req.body.name, req.body.code || null, req.body.description || null]);
  ok(res, db.get('SELECT * FROM schemes WHERE id = ?', [r.lastInsertRowid]));
});
router.get('/funds', requireAuth, requirePermission('scheme.view'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM funds ORDER BY name') });
});
router.post('/funds', requireAuth, requirePermission('scheme.manage'), (req, res) => {
  if (!req.body.name) throw validation('Name is required');
  const r = db.run('INSERT INTO funds (uid, name, code, funding_source, head_of_account) VALUES (?,?,?,?,?)', [uuid(), req.body.name, req.body.code || null, req.body.funding_source || null, req.body.head_of_account || null]);
  ok(res, db.get('SELECT * FROM funds WHERE id = ?', [r.lastInsertRowid]));
});

// ---------------------------- Projects ----------------------------
router.get('/projects', requireAuth, requirePermission('project.view'), (req, res) => {
  const conds = ['p.deleted_at IS NULL'];
  const params = [];
  if (req.query.fyId) { conds.push('p.fy_id = ?'); params.push(int(req.query.fyId)); }
  if (req.query.status) { conds.push('p.status = ?'); params.push(req.query.status); }
  if (req.query.q) { conds.push('(p.work_name LIKE ? OR p.project_code LIKE ?)'); params.push(`%${req.query.q}%`, `%${req.query.q}%`); }
  const where = conds.join(' AND ');
  ok(res, {
    rows: db.all(`SELECT p.*, f.label fy_label, s.name scheme_name, fu.name fund_name, c.legal_name contractor_name
        FROM projects p LEFT JOIN financial_years f ON f.id=p.fy_id LEFT JOIN schemes s ON s.id=p.scheme_id
        LEFT JOIN funds fu ON fu.id=p.fund_id LEFT JOIN contractors c ON c.id=p.contractor_id
        WHERE ${where} ORDER BY p.id DESC`, params),
  });
});
router.post('/projects', requireAuth, requirePermission('project.manage'), (req, res) => {
  if (!req.body.work_name) throw validation('Work name is required');
  if (!req.body.fy_id) throw validation('Financial year is required');
  const r = db.run(
    `INSERT INTO projects (uid, fy_id, scheme_id, fund_id, head_of_account, project_code, work_name, description, location,
       administrative_approval_no, administrative_approval_date, administrative_approval_authority, administrative_approval_amount_minor,
       technical_sanction_no, technical_sanction_date, technical_sanction_authority, technical_sanction_amount_minor,
       estimate_amount_minor, sanctioned_amount_minor, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(), int(req.body.fy_id), int(req.body.scheme_id), int(req.body.fund_id), req.body.head_of_account || null,
      req.body.project_code || null, req.body.work_name, req.body.description || null, req.body.location || null,
      req.body.administrative_approval_no || null, req.body.administrative_approval_date || null, req.body.administrative_approval_authority || null, minor(req.body.administrative_approval_amount_minor),
      req.body.technical_sanction_no || null, req.body.technical_sanction_date || null, req.body.technical_sanction_authority || null, minor(req.body.technical_sanction_amount_minor),
      minor(req.body.estimate_amount_minor), minor(req.body.sanctioned_amount_minor), req.body.status || 'planned', req.user.id,
    ]
  );
  audit.record({ actor: req.user, action: 'project.create', entityType: 'project', entityId: r.lastInsertRowid, entityLabel: req.body.work_name });
  ok(res, db.get('SELECT * FROM projects WHERE id = ?', [r.lastInsertRowid]));
});

// ---------------------------- Contractors ----------------------------
router.get('/contractors', requireAuth, requirePermission('contractor.view'), (req, res) => {
  ok(res, contractorService.listContractors({ q: req.query.q, status: req.query.status, limit: int(req.query.limit) || 100, offset: int(req.query.offset) || 0 }));
});
router.get('/contractors/:id', requireAuth, requirePermission('contractor.view'), (req, res) => {
  const c = contractorService.getContractor(int(req.params.id));
  const docs = db.all('SELECT * FROM contractor_documents WHERE contractor_id = ? ORDER BY id DESC', [c.id]);
  const showSensitive = req.userPermissions.has('contractor.sensitive') || req.user.is_global_admin;
  ok(res, { contractor: c, documents: docs, sensitive: showSensitive ? { pan: c.pan, gst: c.gst, bank_account_no: c.bank_account_no, bank_ifsc: c.bank_ifsc } : null });
});
router.post('/contractors', requireAuth, requirePermission('contractor.manage'), (req, res) => {
  ok(res, contractorService.createContractor(req.body, req.user));
});
router.put('/contractors/:id', requireAuth, requirePermission('contractor.manage'), (req, res) => {
  ok(res, contractorService.updateContractor(int(req.params.id), req.body, req.user));
});
router.post('/contractors/:id/documents', requireAuth, requirePermission('contractor.manage'), (req, res) => {
  ok(res, contractorService.upsertContractorDocument(int(req.params.id), req.body, req.user));
});

// ---------------------------- Tenders ----------------------------
router.get('/tenders', requireAuth, requirePermission('tender.view'), (req, res) => {
  ok(res, tenderService.listTenders({
    fyId: int(req.query.fyId), status: req.query.status, tenderType: req.query.type,
    schemeId: int(req.query.schemeId), fundId: int(req.query.fundId), q: req.query.q,
    limit: int(req.query.limit) || 50, offset: int(req.query.offset) || 0,
  }));
});
router.get('/tenders/:id', requireAuth, requirePermission('tender.view'), wrap(async (req, res) => {
  const t = tenderService.getTender(int(req.params.id));
  const boq = db.all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [t.id]);
  const documents = documentService.listDocuments('tender', t.id);
  const versions = db.all('SELECT * FROM tender_versions WHERE tender_id = ? ORDER BY version_no', [t.id]);
  const steps = workflowService.get('tender', t.id);
  const complianceResult = complianceService.getLatestEvaluation('tender', t.id);
  const boqTotals = financialService.boqTotals(t.id);
  const fy = db.get('SELECT * FROM financial_years WHERE id = ?', [t.fy_id]);
  const scheme = t.scheme_id ? db.get('SELECT name FROM schemes WHERE id = ?', [t.scheme_id]) : null;
  const fund = t.fund_id ? db.get('SELECT name FROM funds WHERE id = ?', [t.fund_id]) : null;
  const award = db.get(
    `SELECT a.*, c.legal_name AS contractor_name FROM awards a LEFT JOIN contractors c ON c.id = a.contractor_id
      WHERE a.tender_id = ? ORDER BY a.id DESC LIMIT 1`, [t.id]);
  ok(res, {
    tender: { ...t, fy_label: fy ? fy.label : null },
    scheme_name: scheme ? scheme.name : null,
    fund_name: fund ? fund.name : null,
    boq, documents, versions, steps, compliance: complianceResult, boqTotals,
    criteria: bidService.listCriteria(t.id),
    bidders: bidService.listBidders(t.id),
    evaluations: db.all('SELECT * FROM technical_evaluations WHERE tender_id = ?', [t.id]),
    award,
    financialBids: db.all('SELECT * FROM financial_bids WHERE tender_id = ?', [t.id]),
  });
}));
router.post('/tenders', requireAuth, requirePermission('tender.manage'), (req, res) => {
  ok(res, tenderService.createTender(req.body, req.user));
});
router.put('/tenders/:id', requireAuth, requirePermission('tender.manage'), (req, res) => {
  ok(res, tenderService.updateTender(int(req.params.id), req.body, req.user));
});
router.post('/tenders/:id/compliance', requireAuth, requireAnyPermission('tender.manage', 'rules.view'), (req, res) => {
  ok(res, tenderService.runCompliance(int(req.params.id), req.user));
});
router.post('/tenders/:id/submit', requireAuth, requirePermission('tender.manage'), (req, res) => {
  ok(res, tenderService.submitForApproval(int(req.params.id), req.user));
});
router.post('/tenders/:id/workflow', requireAuth, requireAnyPermission('tender.approve', 'tender.manage'), (req, res) => {
  ok(res, tenderService.workflowAction(int(req.params.id), req.body.action, req.body.remarks, req.user));
});
router.post('/tenders/:id/nit', requireAuth, requirePermission('nit.manage'), wrap(async (req, res) => {
  ok(res, await tenderService.generateNIT(int(req.params.id), req.user));
}));
router.post('/tenders/:id/publish', requireAuth, requirePermission('tender.publish'), (req, res) => {
  ok(res, tenderService.publish(int(req.params.id), req.body, req.user));
});
router.post('/tenders/:id/bidding', requireAuth, requirePermission('tender.publish'), (req, res) => {
  ok(res, tenderService.startBidding(int(req.params.id), req.user));
});
router.post('/tenders/:id/close-bids', requireAuth, requirePermission('tender.publish'), (req, res) => {
  ok(res, tenderService.closeBids(int(req.params.id), req.user));
});
router.post('/tenders/:id/cancel', requireAuth, requirePermission('tender.cancel'), (req, res) => {
  ok(res, tenderService.cancelTender(int(req.params.id), req.body, req.user));
});
router.post('/tenders/:id/corrigendum', requireAuth, requirePermission('tender.corrigendum'), (req, res) => {
  ok(res, tenderService.createCorrigendum(int(req.params.id), req.body, req.user));
});
router.post('/tenders/:id/retender', requireAuth, requirePermission('tender.retender'), (req, res) => {
  ok(res, tenderService.createRetender(int(req.params.id), req.body, req.user));
});

// BOQ
router.get('/tenders/:id/boq', requireAuth, requirePermission('tender.view'), (req, res) => {
  ok(res, { items: db.all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [int(req.params.id)]), totals: financialService.boqTotals(int(req.params.id)) });
});
router.post('/tenders/:id/boq', requireAuth, requirePermission('boq.manage'), (req, res) => {
  const tid = int(req.params.id);
  const t = tenderService.getTender(tid);
  if (!['draft'].includes(t.status)) throw badRequest('BOQ is locked at this stage');
  const items = Array.isArray(req.body.items) ? req.body.items : [req.body];
  const ins = db.prepare(
    `INSERT INTO boq_items (uid, tender_id, item_no, group_name, description, specification, unit, quantity, estimated_rate_minor, tax_pct, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`
  );
  let order = db.get('SELECT COALESCE(MAX(sort_order),0) m FROM boq_items WHERE tender_id = ?', [tid]).m;
  for (const it of items) {
    order += 1;
    ins.run(uuid(), tid, it.item_no || String(order), it.group_name || null, it.description, it.specification || null, it.unit || null, Number(it.quantity) || 0, minor(it.estimated_rate_minor), Number(it.tax_pct) || 0, order);
  }
  audit.record({ actor: req.user, action: 'boq.add', entityType: 'tender', entityId: tid, entityLabel: t.tender_number });
  ok(res, { items: db.all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [tid]), totals: financialService.boqTotals(tid) });
});
router.put('/tenders/:id/boq/:itemId', requireAuth, requirePermission('boq.manage'), (req, res) => {
  const t = tenderService.getTender(int(req.params.id));
  if (!['draft'].includes(t.status)) throw badRequest('BOQ is locked at this stage');
  db.run(
    `UPDATE boq_items SET item_no=?, group_name=?, description=?, specification=?, unit=?, quantity=?, estimated_rate_minor=?, tax_pct=? WHERE id=?`,
    [req.body.item_no, req.body.group_name || null, req.body.description, req.body.specification || null, req.body.unit || null, Number(req.body.quantity) || 0, minor(req.body.estimated_rate_minor), Number(req.body.tax_pct) || 0, int(req.params.itemId)]
  );
  ok(res, db.get('SELECT * FROM boq_items WHERE id = ?', [int(req.params.itemId)]));
});
router.delete('/tenders/:id/boq/:itemId', requireAuth, requirePermission('boq.manage'), (req, res) => {
  const t = tenderService.getTender(int(req.params.id));
  if (!['draft'].includes(t.status)) throw badRequest('BOQ is locked at this stage');
  db.run('DELETE FROM boq_items WHERE id = ?', [int(req.params.itemId)]);
  ok(res, { success: true });
});

// Bidders & evaluation
router.get('/tenders/:id/bidders', requireAuth, requirePermission('tender.view'), (req, res) => {
  ok(res, { rows: bidService.listBidders(int(req.params.id)) });
});
router.post('/tenders/:id/bidders', requireAuth, requirePermission('bid.manage'), (req, res) => {
  ok(res, bidService.addBidder(int(req.params.id), {
    contractorId: int(req.body.contractor_id),
    bidderLabel: req.body.bidder_label,
    submissionTime: req.body.submission_time,
    emdPaidMinor: minor(req.body.emd_paid_minor),
    emdDetails: req.body.emd_details,
  }, req.user));
});
router.post('/tenders/:id/technical-open', requireAuth, requirePermission('technical_open.manage'), (req, res) => {
  ok(res, bidService.recordTechnicalOpening(int(req.params.id), req.body, req.user));
});
router.get('/tenders/:id/criteria', requireAuth, requirePermission('tender.view'), (req, res) => {
  ok(res, { rows: bidService.listCriteria(int(req.params.id)) });
});
router.post('/tenders/:id/criteria', requireAuth, requirePermission('technical_eval.manage'), (req, res) => {
  ok(res, bidService.addCriterion(int(req.params.id), req.body, req.user));
});
router.post('/tenders/:id/evaluations', requireAuth, requirePermission('technical_eval.manage'), (req, res) => {
  ok(res, bidService.setEvaluation(int(req.params.id), int(req.body.bidder_id), int(req.body.criterion_id), req.body, req.user));
});
router.post('/tenders/:id/finalize-technical', requireAuth, requirePermission('technical_eval.manage'), (req, res) => {
  ok(res, bidService.finalizeTechnicalEvaluation(int(req.params.id), req.body, req.user));
});
router.post('/tenders/:id/financial-bids', requireAuth, requirePermission('financial_eval.manage'), (req, res) => {
  ok(res, bidService.recordFinancialBid(int(req.params.id), int(req.body.bidder_id), req.body, req.user));
});
router.post('/tenders/:id/rank', requireAuth, requirePermission('financial_eval.manage'), (req, res) => {
  ok(res, { rankings: bidService.computeRankings(int(req.params.id), req.user) });
});
router.get('/tenders/:id/comparative', requireAuth, requirePermission('comparative.view'), (req, res) => {
  ok(res, bidService.comparativeStatement(int(req.params.id)));
});

// ---------------------------- Awards ----------------------------
router.get('/awards/:id', requireAuth, requirePermission('award.view'), (req, res) => {
  ok(res, awardService.getAward(int(req.params.id)));
});
router.post('/awards/recommend', requireAuth, requirePermission('award.manage'), (req, res) => {
  ok(res, awardService.recommendAward(int(req.body.tender_id), {
    contractorId: int(req.body.contractor_id),
    awardedAmountMinor: minor(req.body.awarded_amount_minor),
    remarks: req.body.remarks,
  }, req.user));
});
router.post('/awards/:id/approve', requireAuth, requirePermission('award.approve'), (req, res) => {
  ok(res, awardService.approveAward(int(req.params.id), req.body, req.user));
});
router.post('/awards/:id/loa', requireAuth, requirePermission('award.manage'), wrap(async (req, res) => {
  ok(res, await awardService.issueLOA(int(req.params.id), req.user));
}));
router.post('/awards/:id/agreement', requireAuth, requirePermission('award.manage'), (req, res) => {
  ok(res, awardService.createAgreement(int(req.params.id), req.body, req.user));
});
router.post('/awards/:id/work-order', requireAuth, requirePermission('award.manage'), (req, res) => {
  ok(res, awardService.issueWorkOrder(int(req.params.id), req.body, req.user));
});

// ---------------------------- Execution / Measurement / Bills / Payments / Completion ----------------------------
router.post('/projects/:id/progress', requireAuth, requirePermission('execution.manage'), (req, res) => {
  ok(res, lifecycleService.recordProgress(int(req.params.id), req.body, req.user));
});
router.post('/projects/:id/extensions', requireAuth, requirePermission('execution.manage'), (req, res) => {
  ok(res, lifecycleService.addExtensionRequest(int(req.params.id), req.body, req.user));
});

router.get('/projects/:id/measurements', requireAuth, requirePermission('project.view'), (req, res) => {
  const rows = db.all('SELECT * FROM measurements WHERE project_id = ? ORDER BY id', [int(req.params.id)]);
  for (const m of rows) m.items = db.all('SELECT * FROM measurement_items WHERE measurement_id = ? ORDER BY id', [m.id]);
  ok(res, { rows });
});
router.post('/projects/:id/measurements', requireAuth, requirePermission('measurement.manage'), (req, res) => {
  ok(res, lifecycleService.createMeasurement(int(req.params.id), req.body, req.user));
});
router.post('/measurements/:id/items', requireAuth, requirePermission('measurement.manage'), (req, res) => {
  ok(res, lifecycleService.addMeasurementItem(int(req.params.id), req.body, req.user));
});
router.post('/measurements/:id/status', requireAuth, requirePermission('measurement.approve'), (req, res) => {
  ok(res, lifecycleService.setMeasurementStatus(int(req.params.id), req.body.status, req.user));
});

router.get('/projects/:id/bills', requireAuth, requirePermission('bill.view'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM bills WHERE project_id = ? ORDER BY id', [int(req.params.id)]) });
});
router.post('/projects/:id/bills', requireAuth, requirePermission('bill.manage'), (req, res) => {
  ok(res, lifecycleService.createBill(int(req.params.id), req.body, req.user));
});
router.put('/bills/:id', requireAuth, requirePermission('bill.manage'), (req, res) => {
  ok(res, lifecycleService.updateBillDraft(int(req.params.id), req.body, req.user));
});
router.post('/bills/:id/submit', requireAuth, requirePermission('bill.manage'), (req, res) => {
  ok(res, lifecycleService.submitBill(int(req.params.id), req.user));
});
router.post('/bills/:id/workflow', requireAuth, requirePermission('bill.approve'), (req, res) => {
  ok(res, lifecycleService.billWorkflowAction(int(req.params.id), req.body.action, req.body.remarks, req.user));
});
router.post('/bills/:id/payments', requireAuth, requirePermission('payment.manage'), (req, res) => {
  ok(res, lifecycleService.recordPayment(int(req.params.id), req.body, req.user));
});

router.get('/projects/:id/completion', requireAuth, requirePermission('project.view'), (req, res) => {
  ok(res, lifecycleService.getOrCreateCompletion(int(req.params.id)));
});
router.post('/projects/:id/completion', requireAuth, requirePermission('completion.manage'), (req, res) => {
  ok(res, lifecycleService.advanceCompletion(int(req.params.id), req.body, req.user));
});
router.post('/projects/:id/completion/certificate', requireAuth, requirePermission('completion.manage'), wrap(async (req, res) => {
  ok(res, await lifecycleService.generateCompletionCertificate(int(req.params.id), req.user));
}));

// ---------------------------- Documents ----------------------------
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: require('../config').maxUploadBytes } });
router.post('/documents', requireAuth, requirePermission('document.upload'), upload.single('file'), (req, res) => {
  if (!req.file) throw badRequest('No file uploaded');
  const rec = documentService.storeFile({
    entityType: req.body.entity_type || 'general',
    entityId: int(req.body.entity_id),
    category: req.body.category || 'general',
    originalName: req.file.originalname,
    buffer: req.file.buffer,
    mimeType: req.file.mimetype,
    uploadedBy: req.user.id,
  });
  audit.record({ actor: req.user, action: 'document.upload', entityType: 'document', entityId: rec.id, entityLabel: rec.original_name });
  ok(res, rec);
});
router.get('/documents/:id/download', requireAuth, requirePermission('document.download'), (req, res) => {
  const doc = documentService.getDocument(int(req.params.id));
  documentService.logDownload(doc, req);
  res.download(documentService.filePath(doc), doc.original_name);
});
router.get('/documents/:id', requireAuth, requirePermission('document.view'), (req, res) => {
  ok(res, documentService.getDocument(int(req.params.id)));
});

// Generated PDFs (NIT etc.)
router.get('/generated/:docId/download', requireAuth, requirePermission('document.download'), (req, res) => {
  const g = db.get('SELECT * FROM document_generations WHERE id = ?', [int(req.params.docId)]);
  if (!g) throw badRequest('Generation record not found');
  const doc = documentService.getDocument(g.document_id);
  documentService.logDownload(doc, req);
  res.download(documentService.filePath(doc), doc.original_name);
});

// ---------------------------- Rules & references ----------------------------
router.get('/rulesets', requireAuth, requirePermission('rules.view'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM rulesets ORDER BY id') });
});
router.get('/rulesets/:id/rules', requireAuth, requirePermission('rules.view'), (req, res) => {
  ok(res, { rows: complianceService.getRules(int(req.params.id)) });
});
router.get('/rule-references', requireAuth, requirePermission('rules.view'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM rule_references ORDER BY id') });
});
router.post('/rulesets', requireAuth, requirePermission('rules.manage'), (req, res) => {
  const r = db.run(
    `INSERT INTO rulesets (uid, name, issuing_authority, jurisdiction, effective_date, expiry_date, reference_number, source_url, version, is_active, notes, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`,
    [uuid(), req.body.name, req.body.issuing_authority || null, req.body.jurisdiction || null, req.body.effective_date || null, req.body.expiry_date || null, req.body.reference_number || null, req.body.source_url || null, req.body.version || 1, req.body.is_active !== undefined ? req.body.is_active : 1, req.body.notes || null, req.user.id]
  );
  audit.record({ actor: req.user, action: 'ruleset.create', entityType: 'ruleset', entityId: r.lastInsertRowid, entityLabel: req.body.name });
  ok(res, db.get('SELECT * FROM rulesets WHERE id = ?', [r.lastInsertRowid]));
});
router.post('/rulesets/:id/rules', requireAuth, requirePermission('rules.manage'), (req, res) => {
  const ruleset = db.get('SELECT * FROM rulesets WHERE id = ?', [int(req.params.id)]);
  if (!ruleset) throw badRequest('Rule set not found');
  const r = db.run(
    `INSERT INTO rules (uid, ruleset_id, code, title, description, category, severity, rule_type, config, reference_id, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
    [uuid(), ruleset.id, req.body.code, req.body.title, req.body.description || null, req.body.category || 'general', req.body.severity || 'info', req.body.rule_type || 'manual', json(req.body.config || {}), int(req.body.reference_id), int(req.body.sort_order) || 0]
  );
  ok(res, db.get('SELECT * FROM rules WHERE id = ?', [r.lastInsertRowid]));
});

// ---------------------------- Templates ----------------------------
router.get('/templates', requireAuth, requirePermission('template.manage'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM templates ORDER BY code, version DESC') });
});
router.put('/templates/:id', requireAuth, requirePermission('template.manage'), (req, res) => {
  const t = db.get('SELECT * FROM templates WHERE id = ?', [int(req.params.id)]);
  if (!t) throw badRequest('Template not found');
  db.run('UPDATE templates SET content_html = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [req.body.content_html, t.id]);
  audit.record({ actor: req.user, action: 'template.update', entityType: 'template', entityId: t.id, entityLabel: `${t.code} v${t.version}` });
  ok(res, db.get('SELECT * FROM templates WHERE id = ?', [t.id]));
});

// ---------------------------- Notifications ----------------------------
router.get('/notifications', requireAuth, (req, res) => {
  ok(res, { rows: notifications.listForUser(req.user.id, { unreadOnly: req.query.unread === '1' }), unread: notifications.unreadCount(req.user.id) });
});
router.post('/notifications/read', requireAuth, (req, res) => {
  ok(res, { updated: notifications.markRead(req.user.id, req.body.ids || []) });
});

// ---------------------------- Audit ----------------------------
router.get('/audit', requireAuth, requirePermission('audit.view'), (req, res) => {
  const conds = ['1=1'];
  const params = [];
  if (req.query.entityType) { conds.push('entity_type = ?'); params.push(req.query.entityType); }
  if (req.query.entityId) { conds.push('entity_id = ?'); params.push(int(req.query.entityId)); }
  if (req.query.action) { conds.push('action LIKE ?'); params.push(`%${req.query.action}%`); }
  if (req.query.q) { conds.push('(entity_label LIKE ? OR actor_name LIKE ?)'); params.push(`%${req.query.q}%`, `%${req.query.q}%`); }
  const where = conds.join(' AND ');
  ok(res, {
    rows: db.all(`SELECT * FROM audit_logs WHERE ${where} ORDER BY id DESC LIMIT ? OFFSET ?`, [...params, int(req.query.limit) || 100, int(req.query.offset) || 0]),
    total: db.get(`SELECT COUNT(*) c FROM audit_logs WHERE ${where}`, params).c,
  });
});

// ---------------------------- Users ----------------------------
router.get('/users', requireAuth, requirePermission('user.view'), (req, res) => {
  ok(res, { rows: db.all(`SELECT u.id, u.uid, u.email, u.name, u.designation, u.is_active, u.is_global_admin, u.panchayat_id, u.created_at FROM users u WHERE u.deleted_at IS NULL ORDER BY u.id`) });
});
router.post('/users', requireAuth, requirePermission('user.manage'), (req, res) => {
  if (!req.body.email || !req.body.name || !req.body.password) throw validation('Email, name and password are required');
  if (!passwordStrength(req.body.password)) throw validation('Password must be at least 8 characters with upper, lower and digits');
  const email = String(req.body.email).trim().toLowerCase();
  if (db.get('SELECT id FROM users WHERE email = ?', [email])) throw badRequest('Email already in use');
  const r = db.run(
    `INSERT INTO users (uid, email, password_hash, name, designation, phone, panchayat_id, is_global_admin, is_active)
     VALUES (?,?,?,?,?,?,?,?,?)`,
    [uuid(), email, hashPassword(req.body.password), req.body.name, req.body.designation || null, req.body.phone || null, int(req.body.panchayat_id), req.body.is_global_admin ? 1 : 0, 1]
  );
  const roleIds = Array.isArray(req.body.role_codes) ? req.body.role_codes : [];
  for (const code of roleIds) {
    const role = db.get('SELECT id FROM roles WHERE code = ?', [code]);
    if (role) db.run('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [r.lastInsertRowid, role.id]);
  }
  audit.record({ actor: req.user, action: 'user.create', entityType: 'user', entityId: r.lastInsertRowid, entityLabel: email });
  ok(res, db.get(`SELECT id, uid, email, name, designation, is_active, is_global_admin, panchayat_id FROM users WHERE id = ?`, [r.lastInsertRowid]));
});
router.put('/users/:id', requireAuth, requirePermission('user.manage'), (req, res) => {
  const u = db.get('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [int(req.params.id)]);
  if (!u) throw badRequest('User not found');
  db.run('UPDATE users SET name = ?, designation = ?, phone = ?, is_active = ?, panchayat_id = ? WHERE id = ?', [req.body.name ?? u.name, req.body.designation ?? u.designation, req.body.phone ?? u.phone, req.body.is_active !== undefined ? req.body.is_active : u.is_active, int(req.body.panchayat_id) ?? u.panchayat_id, u.id]);
  if (req.body.password) {
    if (!passwordStrength(req.body.password)) throw validation('Password is too weak');
    db.run('UPDATE users SET password_hash = ? WHERE id = ?', [hashPassword(req.body.password), u.id]);
  }
  if (Array.isArray(req.body.role_codes)) {
    db.run('DELETE FROM user_roles WHERE user_id = ?', [u.id]);
    for (const code of req.body.role_codes) {
      const role = db.get('SELECT id FROM roles WHERE code = ?', [code]);
      if (role) db.run('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [u.id, role.id]);
    }
    require('../auth/rbac').clearCache(u.id);
  }
  audit.record({ actor: req.user, action: 'user.update', entityType: 'user', entityId: u.id, entityLabel: u.email });
  ok(res, { success: true });
});

// ---------------------------- Settings ----------------------------
router.get('/settings', requireAuth, requirePermission('panchayat.view'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM settings ORDER BY key') });
});
router.put('/settings', requireAuth, requirePermission('settings.manage'), (req, res) => {
  for (const [k, v] of Object.entries(req.body || {})) {
    db.run('INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP', [k, v === null ? null : String(v)]);
  }
  audit.record({ actor: req.user, action: 'settings.update', entityType: 'settings', entityLabel: Object.keys(req.body || {}).join(',') });
  ok(res, { success: true });
});

// ---------------------------- Backup ----------------------------
router.get('/backups', requireAuth, requirePermission('backup.manage'), (req, res) => {
  ok(res, { rows: db.all('SELECT * FROM backups ORDER BY id DESC') });
});
router.post('/backups', requireAuth, requirePermission('backup.manage'), wrap(async (req, res) => {
  const backup = require('../scripts/backup');
  const result = await backup.runBackup({ createdBy: req.user.id });
  ok(res, result);
}));

// ---------------------------- Reference data for forms ----------------------------
router.get('/reference', requireAuth, (req, res) => {
  ok(res, {
    financialYears: fyService.listFY(),
    schemes: db.all('SELECT * FROM schemes ORDER BY name'),
    funds: db.all('SELECT * FROM funds ORDER BY name'),
    rulesets: db.all('SELECT * FROM rulesets WHERE is_active = 1 ORDER BY id'),
    contractors: db.all(`SELECT id, legal_name, business_name, contractor_code, registration_class, status FROM contractors WHERE deleted_at IS NULL ORDER BY legal_name`),
    roles: db.all('SELECT code, name FROM roles ORDER BY id'),
    panchayat: db.get('SELECT * FROM panchayats ORDER BY id LIMIT 1'),
  });
});

module.exports = router;
