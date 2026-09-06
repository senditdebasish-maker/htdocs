'use strict';

const db = require('../db/database');
const config = require('../config');
const { PdfBuilder, renderPdf } = require('./pdfService');
const money = require('../util/money');
const dates = require('../util/dates');

/**
 * Document generation service.
 *
 * Builds structured, printable A4 PDFs from live database records. All money
 * is rendered from integer minor units via money.formatMinorINR (no floats).
 */

function panchayatContext() {
  const p = db.get('SELECT * FROM panchayats ORDER BY id LIMIT 1');
  return p || {};
}

function tenderContext(tenderId) {
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [tenderId]);
  if (!t) return null;
  const fy = db.get('SELECT * FROM financial_years WHERE id = ?', [t.fy_id]);
  const scheme = t.scheme_id ? db.get('SELECT * FROM schemes WHERE id = ?', [t.scheme_id]) : null;
  const fund = t.fund_id ? db.get('SELECT * FROM funds WHERE id = ?', [t.fund_id]) : null;
  const project = t.project_id ? db.get('SELECT * FROM projects WHERE id = ?', [t.project_id]) : null;
  const boq = db.all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [tenderId]);
  return { tender: t, fy, scheme, fund, project, boq };
}

function fmtAmount(minor) {
  return minor === null || minor === undefined ? '—' : money.formatMinorINR(minor);
}

function fmtDate(iso) {
  return iso ? dates.formatDate(iso) : '—';
}

/** Replace {{field}} placeholders in a template with data values. */
function mergeTemplate(templateHtml, data) {
  return String(templateHtml || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, key) => {
    const v = key.split('.').reduce((o, k) => (o == null ? o : o[k]), data);
    return v === null || v === undefined ? '' : String(v);
  });
}

/** Data bag for NIT template merge fields. */
function nitMergeData(tenderId) {
  const ctx = tenderContext(tenderId);
  if (!ctx) return {};
  const { tender: t, fy, scheme } = ctx;
  const p = panchayatContext();
  return {
    panchayat_name: p.gram_panchayat || config.systemName,
    panchayat_address: p.office_address || '',
    panchayat_phone: p.phone || '',
    panchayat_email: p.email || '',
    nit_number: t.nit_number || t.tender_number,
    nit_date: dates.formatDate(t.publication_date || t.created_at),
    tender_number: t.tender_number,
    fy_label: fy ? fy.label : '',
    work_name: t.work_name || t.title,
    description: t.description || '',
    location: t.location || '',
    estimated_cost: fmtAmount(t.estimated_cost_minor),
    emd: fmtAmount(t.emd_minor),
    tender_fee: fmtAmount(t.tender_fee_minor),
    completion_period: t.completion_period_days ? `${t.completion_period_days} days` : 'As per tender document',
    bid_start_date: fmtDate(t.bid_start_date),
    bid_close_date: fmtDate(t.bid_close_date),
    technical_open_date: fmtDate(t.technical_open_date),
    financial_open_date: fmtDate(t.financial_open_date),
    eligibility_notes: t.eligibility_notes || 'As per tender document.',
    special_conditions: t.special_conditions || t.general_conditions || 'As per tender document.',
    authority_name: p.pradhan || p.panchayat_secretary || '',
    authority_designation: p.pradhan ? 'Pradhan' : 'Panchayat Secretary',
    scheme: scheme ? scheme.name : '',
    generated_at: dates.formatDateTime(new Date().toISOString()),
    verification_code: '',
    system_name: config.systemName,
  };
}

/** Build a NIT PDF. */
function buildNIT(tenderId) {
  const ctx = tenderContext(tenderId);
  if (!ctx) throw new Error('Tender not found');
  const { tender: t, fy } = ctx;
  const p = panchayatContext();
  const b = new PdfBuilder({
    title: 'Notice Inviting Tender',
    panchayat: p,
    meta: {
      docNumber: t.nit_number || t.tender_number,
      fy: fy ? fy.label : '',
      date: dates.formatDate(new Date().toISOString()),
      generatedAt: dates.formatDateTime(new Date().toISOString()),
    },
  });
  b.build();
  b.heading('NOTICE INVITING TENDER (NIT)', { underline: true });
  b.labelValue('NIT / Tender No', t.nit_number || t.tender_number);
  b.labelValue('Financial Year', fy ? fy.label : '');
  b.labelValue('Date', dates.formatDate(t.publication_date || t.created_at));
  b.doc.moveDown(0.4);
  b.table(
    [
      { key: 'k', header: 'Particulars', width: 3 },
      { key: 'v', header: 'Details', width: 5 },
    ],
    [
      { k: 'Name of Work', v: t.work_name || t.title },
      { k: 'Description', v: t.description || '' },
      { k: 'Location', v: t.location || '' },
      { k: 'Estimated Cost', v: fmtAmount(t.estimated_cost_minor) },
      { k: 'EMD', v: fmtAmount(t.emd_minor) },
      { k: 'Tender Fee', v: fmtAmount(t.tender_fee_minor) },
      { k: 'Completion Period', v: t.completion_period_days ? `${t.completion_period_days} days` : 'As per tender document' },
      { k: 'Bid Start Date', v: fmtDate(t.bid_start_date) },
      { k: 'Bid Closing Date', v: fmtDate(t.bid_close_date) },
      { k: 'Technical Bid Opening', v: fmtDate(t.technical_open_date) },
      { k: 'Financial Bid Opening', v: fmtDate(t.financial_open_date) },
    ]
  );
  b.heading('Eligibility', { size: 11 });
  b.para(t.eligibility_notes || 'As per tender document.');
  b.heading('Conditions', { size: 11 });
  b.para(t.special_conditions || t.general_conditions || 'As per tender document.');
  b.signatureBlock([
    { title: p.pradhan || '', designation: 'Pradhan' },
    { title: p.panchayat_secretary || '', designation: 'Panchayat Secretary' },
  ]);
  b.generationNotice();
  return b;
}

/** Build a comparative statement PDF. */
function buildComparative(tenderId) {
  const ctx = tenderContext(tenderId);
  if (!ctx) throw new Error('Tender not found');
  const { tender: t, fy } = ctx;
  const p = panchayatContext();
  const bidders = db.all(
    `SELECT b.*, c.legal_name, c.business_name, c.registration_class
       FROM tender_bidders b JOIN contractors c ON c.id = b.contractor_id
      WHERE b.tender_id = ? ORDER BY b.rank ASC NULLS LAST, b.id`,
    [tenderId]
  );
  const financials = db.all(
    `SELECT fb.*, tb.contractor_id FROM financial_bids fb JOIN tender_bidders tb ON tb.id = fb.bidder_id
      WHERE fb.tender_id = ?`,
    [tenderId]
  );
  const finByBidder = {};
  for (const f of financials) finByBidder[f.bidder_id] = f;

  const b = new PdfBuilder({
    title: 'Comparative Statement',
    panchayat: p,
    meta: {
      docNumber: t.tender_number,
      fy: fy ? fy.label : '',
      generatedAt: dates.formatDateTime(new Date().toISOString()),
    },
  });
  b.build();
  b.heading('COMPARATIVE STATEMENT', { underline: true });
  b.labelValue('Tender No', t.tender_number);
  b.labelValue('Work', t.work_name || t.title);
  b.labelValue('Estimated Cost', fmtAmount(t.estimated_cost_minor));
  b.doc.moveDown(0.4);

  const columns = [
    { key: 'rank', header: 'Rank', width: 1 },
    { key: 'bidder', header: 'Bidder', width: 3.5 },
    { key: 'qual', header: 'Qualification', width: 2 },
    { key: 'amount', header: 'Quoted Amount (₹)', width: 2.5, align: 'right' },
    { key: 'var', header: 'Variation %', width: 1.6, align: 'right' },
  ];
  const rows = bidders.map((bd) => {
    const f = finByBidder[bd.id];
    const quoted = f ? f.total_amount_minor : null;
    const variation = quoted !== null && t.estimated_cost_minor ? money.pctDiff(t.estimated_cost_minor, quoted) : null;
    return {
      rank: bd.rank ? `L${bd.rank}` : '—',
      bidder: bd.legal_name || bd.business_name || bd.bidder_label,
      qual: bd.bid_status === 'technically_qualified' ? 'Qualified' : bd.bid_status,
      amount: quoted !== null ? money.fromMinor(quoted) : '—',
      var: variation !== null ? `${variation.toFixed(2)}%` : '—',
    };
  });
  b.table(columns, rows);
  b.para('Note: Amounts are computed from recorded financial bids. This statement reconciles against the recorded bidder and financial-bid data.', { size: 8 });
  b.signatureBlock([
    { title: '', designation: 'Tender Committee' },
    { title: '', designation: 'Competent Authority' },
  ]);
  b.generationNotice();
  return b;
}

/** Build a LOA PDF from an award. */
function buildLOA(awardId) {
  const a = db.get('SELECT * FROM awards WHERE id = ?', [awardId]);
  if (!a) throw new Error('Award not found');
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [a.tender_id]);
  const c = db.get('SELECT * FROM contractors WHERE id = ?', [a.contractor_id]);
  const p = panchayatContext();
  const b = new PdfBuilder({
    title: 'Letter of Acceptance',
    panchayat: p,
    meta: { docNumber: a.loa_number, generatedAt: dates.formatDateTime(new Date().toISOString()) },
  });
  b.build();
  b.heading('LETTER OF ACCEPTANCE (LOA)', { underline: true });
  b.labelValue('LOA No', a.loa_number);
  b.labelValue('Date', dates.formatDate(a.loa_date || a.approval_date));
  b.doc.moveDown(0.4);
  b.para(`To,`);
  b.para(`${c.legal_name}${c.business_name ? ' (' + c.business_name + ')' : ''}`);
  b.para(c.address || '');
  b.doc.moveDown(0.3);
  b.para(`Your bid for the work "${t.work_name || t.title}" (Tender No. ${t.tender_number}) has been accepted at a total value of ${fmtAmount(a.awarded_amount_minor)}.`);
  b.para(`The completion period is ${t.completion_period_days ? t.completion_period_days + ' days' : 'as per tender'} from the date of issue of the Work Order. Please submit the required security deposit of ${fmtAmount(a.security_deposit_minor)} and execute the agreement within the stipulated time.`);
  b.signatureBlock([{ title: p.pradhan || '', designation: 'Competent Authority' }]);
  b.generationNotice();
  return b;
}

/** Build a Work Order PDF. */
function buildWorkOrder(workOrderId) {
  const wo = db.get('SELECT * FROM work_orders WHERE id = ?', [workOrderId]);
  if (!wo) throw new Error('Work order not found');
  const t = db.get('SELECT * FROM tenders WHERE id = ?', [wo.tender_id]);
  const ag = wo.agreement_id ? db.get('SELECT * FROM agreements WHERE id = ?', [wo.agreement_id]) : null;
  const c = db.get('SELECT * FROM contractors WHERE id = ?', [wo.contractor_id]);
  const p = panchayatContext();
  const b = new PdfBuilder({
    title: 'Work Order',
    panchayat: p,
    meta: { docNumber: wo.work_order_number, generatedAt: dates.formatDateTime(new Date().toISOString()) },
  });
  b.build();
  b.heading('WORK ORDER', { underline: true });
  b.labelValue('Work Order No', wo.work_order_number);
  b.labelValue('Tender No', t.tender_number);
  b.labelValue('Agreement No', ag ? ag.agreement_number : '—');
  b.doc.moveDown(0.4);
  b.para(`To,`);
  b.para(c.legal_name);
  b.para(c.address || '');
  b.doc.moveDown(0.3);
  b.para(`You are hereby directed to commence the work "${t.work_name || t.title}" at ${t.location || 'the specified site'} for a value of ${fmtAmount(wo.amount_minor)}.`);
  b.table(
    [
      { key: 'k', header: 'Particulars', width: 3 },
      { key: 'v', header: 'Details', width: 5 },
    ],
    [
      { k: 'Start Date', v: fmtDate(wo.start_date) },
      { k: 'Completion Date', v: fmtDate(wo.completion_date) },
      { k: 'Contract Value', v: fmtAmount(wo.amount_minor) },
    ]
  );
  b.para(wo.conditions || 'Conditions as per agreement.');
  b.signatureBlock([{ title: p.pradhan || '', designation: 'Competent Authority' }]);
  b.generationNotice();
  return b;
}

/** Build a Completion Certificate PDF. */
function buildCompletionCertificate(completionId) {
  const comp = db.get('SELECT * FROM completions WHERE id = ?', [completionId]);
  if (!comp) throw new Error('Completion record not found');
  const t = comp.tender_id ? db.get('SELECT * FROM tenders WHERE id = ?', [comp.tender_id]) : null;
  const prj = db.get('SELECT * FROM projects WHERE id = ?', [comp.project_id]);
  const contractor = prj && prj.contractor_id ? db.get('SELECT * FROM contractors WHERE id = ?', [prj.contractor_id]) : null;
  const p = panchayatContext();
  const b = new PdfBuilder({
    title: 'Completion Certificate',
    panchayat: p,
    meta: { generatedAt: dates.formatDateTime(new Date().toISOString()) },
  });
  b.build();
  b.heading('COMPLETION CERTIFICATE', { underline: true });
  b.labelValue('Date', dates.formatDate(comp.completion_date || comp.handover_date));
  b.doc.moveDown(0.4);
  b.para(`This is to certify that the work "${prj ? prj.work_name : ''}"${t ? ' under Tender No. ' + t.tender_number : ''} awarded to ${contractor ? contractor.legal_name : 'the contractor'} has been completed and inspected, and the final measurement and final bill have been processed.`);
  b.table(
    [
      { key: 'k', header: 'Particulars', width: 3 },
      { key: 'v', header: 'Details', width: 5 },
    ],
    [
      { k: 'Completion Date', v: fmtDate(comp.completion_date) },
      { k: 'Inspection Date', v: fmtDate(comp.inspection_date) },
      { k: 'Handover Date', v: fmtDate(comp.handover_date) },
      { k: 'Security Release', v: fmtDate(comp.security_release_date) },
    ]
  );
  b.signatureBlock([
    { title: '', designation: 'Technical Officer' },
    { title: p.pradhan || '', designation: 'Competent Authority' },
  ]);
  b.generationNotice();
  return b;
}

module.exports = {
  panchayatContext,
  tenderContext,
  mergeTemplate,
  nitMergeData,
  buildNIT,
  buildComparative,
  buildLOA,
  buildWorkOrder,
  buildCompletionCertificate,
  fmtAmount,
  fmtDate,
};
