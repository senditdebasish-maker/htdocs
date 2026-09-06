'use strict';

const db = require('./database');
const { hashPassword } = require('../auth/passwords');
const { PERMISSIONS, ROLE_DEFS } = require('../auth/permissions');
const { uuid } = require('../util/uuid');
const { json } = require('./database');
const config = require('../config');

function seedPermissions() {
  const existing = db.get('SELECT COUNT(*) c FROM permissions').c;
  if (existing > 0) return;
  const ins = db.prepare('INSERT INTO permissions (code, name, category) VALUES (?,?,?)');
  for (const p of PERMISSIONS) ins.run(p.code, p.name, p.category);
}

function seedRoles() {
  const existing = db.get('SELECT COUNT(*) c FROM roles').c;
  if (existing > 0) return;
  const insRole = db.prepare(
    'INSERT INTO roles (uid, code, name, description, is_system) VALUES (?,?,?,?,?)'
  );
  const insPerm = db.prepare(
    'INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)'
  );
  const permId = (code) => db.get('SELECT id FROM permissions WHERE code = ?', [code]);
  for (const def of ROLE_DEFS) {
    const r = insRole.run(uuid(), def.code, def.name, def.description, def.system ? 1 : 0);
    const roleId = Number(r.lastInsertRowid);
    if (def.allPermissions) {
      for (const p of PERMISSIONS) insPerm.run(roleId, p.id ? p.id : permId(p.code).id);
    } else {
      for (const code of def.permissions) {
        const p = permId(code);
        if (p) insPerm.run(roleId, p.id);
      }
    }
  }
}

function seedAdmin() {
  const existing = db.get('SELECT COUNT(*) c FROM users').c;
  if (existing > 0) return;
  const r = db.run(
    `INSERT INTO users (uid, email, password_hash, name, designation, is_global_admin, is_active)
     VALUES (?,?,?,?,?,1,1)`,
    [uuid(), config.seedAdminEmail, hashPassword(config.seedAdminPassword), 'System Administrator', 'Super Admin']
  );
  const role = db.get(`SELECT id FROM roles WHERE code = 'super_admin'`);
  db.run('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [r.lastInsertRowid, role.id]);
  // eslint-disable-next-line no-console
  console.log(`[seed] created global admin: ${config.seedAdminEmail}`);
}

function seedPanchayat() {
  const existing = db.get('SELECT COUNT(*) c FROM panchayats').c;
  if (existing > 0) return;
  db.run(
    `INSERT INTO panchayats
      (uid, state, district, block, gram_panchayat, gp_code, office_address, pin, phone, email,
       pradhan, upa_pradhan, panchayat_secretary, technical_officer, accounts_officer)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      uuid(),
      'West Bengal',
      'Paschim Bardhaman',
      'Sample Block',
      'Sample Gram Panchayat',
      'GP-SAMPLE-001',
      'Village & PO — Sample, Dist. Paschim Bardhaman, West Bengal',
      '713301',
      '0341-0000000',
      'gp.sample@wb.gov.in',
      'Pradhan, Sample GP',
      'Upa-Pradhan, Sample GP',
      'Secretary, Sample GP',
      'Technical Officer, Sample GP',
      'Accounts Officer, Sample GP',
    ]
  );
  // eslint-disable-next-line no-console
  console.log('[seed] created sample Panchayat configuration');
}

function seedFinancialYears() {
  const existing = db.get('SELECT COUNT(*) c FROM financial_years').c;
  if (existing > 0) return;
  const ins = db.prepare(
    `INSERT INTO financial_years (uid, label, start_year, start_date, end_date, status, is_current)
     VALUES (?,?,?,?,?,?,?)`
  );
  const years = [
    { label: '2024-25', start: '2024-04-01', end: '2025-03-31', current: 0 },
    { label: '2025-26', start: '2025-04-01', end: '2026-03-31', current: 0 },
    { label: '2026-27', start: '2026-04-01', end: '2027-03-31', current: 1 },
    { label: '2027-28', start: '2027-04-01', end: '2028-03-31', current: 0 },
  ];
  for (const y of years) {
    ins.run(uuid(), y.label, Number(y.label.slice(0, 4)), y.start, y.end, 'open', y.current);
  }
}

function seedRuleReferences() {
  const existing = db.get('SELECT COUNT(*) c FROM rule_references').c;
  if (existing > 0) return;
  const ins = db.prepare(
    `INSERT INTO rule_references
      (uid, title, authority, manual_name, reference_number, publication_date, effective_date, source_url, version, notes)
     VALUES (?,?,?,?,?,?,?,?,?,?)`
  );
  const refs = [
    {
      title: 'New Purchase Policy of West Bengal Government',
      authority: 'Government of West Bengal, Finance Department (Audit Branch)',
      manual_name: 'W.B. Financial Rules / Purchase Policy',
      reference_number: 'No. 5400-F(Y)',
      publication_date: '2012-06-25',
      effective_date: '2012-06-25',
      source_url: 'https://wbxpress.com/new-purchase-policy/',
      version: 'as published',
      notes: 'Manner of tender publication and minimum notice periods (7/14/21 days) for supply of articles/stores and execution of works & services. Verify current circulars.',
    },
    {
      title: 'Amendment of Rule 177 of WBFR regarding Tenders',
      authority: 'Government of West Bengal, Finance Department',
      manual_name: 'W.B. Financial Rules',
      reference_number: 'WBFR Rule 177 (as amended)',
      publication_date: '2014-04-27',
      effective_date: '2014-04-27',
      source_url: 'https://wbxpress.com/amendment-rule-177-wbfr-tenders/',
      version: 'as amended',
      notes: 'Mandatory e-tendering through https://wbtenders.gov.in for works valued at Rs. 5 lakh and above.',
    },
    {
      title: 'Guidelines for Short Notice Tender in Emergent Situations',
      authority: 'Government of West Bengal, Irrigation & Waterways Directorate',
      manual_name: 'I&WD Guidelines',
      reference_number: 'Notification No. 5400-F(Y) dated 25.06.2012 / WBFR Rule 47(8)',
      publication_date: '2012-06-25',
      effective_date: '2012-06-25',
      source_url: 'https://wbxpress.com/guidelines-short-notice-tender-emergent-situations/',
      version: 'as published',
      notes: 'Short-notice (3-day) off-line tenders for emergent works up to Rs. 10 lakh. Department-specific; verify applicability to Gram Panchayats.',
    },
    {
      title: 'WB e-Procurement System (two-bid)',
      authority: 'Government of West Bengal — eProcurement System',
      manual_name: 'e-NIT General Terms & Conditions',
      reference_number: 'wbtenders.gov.in',
      publication_date: null,
      effective_date: null,
      source_url: 'https://wbtenders.gov.in/nicgep/app',
      version: 'current',
      notes: 'e-Tenders use a two-bid system (technical + financial). Re-tender when response is fewer than three bidders. Department-specific; verify GP applicability.',
    },
    {
      title: 'P&RD Department — Tenders',
      authority: 'Department of Panchayats & Rural Development, Government of West Bengal',
      manual_name: 'P&RD tender listings',
      reference_number: 'prd.wb.gov.in/tenders',
      publication_date: null,
      effective_date: null,
      source_url: 'https://prd.wb.gov.in/tenders/list',
      version: 'current',
      notes: 'Official P&RD tender listings. Consult for GP-specific tender conditions and formats.',
    },
  ];
  for (const r of refs) {
    ins.run(
      uuid(), r.title, r.authority, r.manual_name, r.reference_number, r.publication_date,
      r.effective_date, r.source_url, r.version, r.notes
    );
  }
}

function seedDefaultRuleset() {
  const existing = db.get('SELECT COUNT(*) c FROM rulesets').c;
  if (existing > 0) return;

  const rs = db.run(
    `INSERT INTO rulesets
      (uid, name, issuing_authority, jurisdiction, effective_date, reference_number, source_url, version, is_active, notes)
     VALUES (?,?,?,?,?,?,?,1,1,?)`,
    [
      uuid(),
      'WB Gram Panchayat Procurement — Base Rule Set',
      'Government of West Bengal (Finance Dept. / P&RD Dept.)',
      'West Bengal',
      '2012-06-25',
      'WBFR + FD No. 5400-F(Y) dt 25.06.2012',
      'https://prd.wb.gov.in/',
      'This rule set encodes current published GoWB procurement norms as system checks. It does not constitute legal advice. Verify GP-specific circulars before relying on any rule.',
    ]
  );
  const rulesetId = Number(rs.lastInsertRowid);

  const refId = (title) => {
    const r = db.get('SELECT id FROM rule_references WHERE title LIKE ?', [`%${title.slice(0, 20)}%`]);
    return r ? r.id : null;
  };
  const purchasePolicyId = refId('New Purchase Policy');
  const rule177Id = refId('Amendment of Rule 177');
  const shortNoticeId = refId('Guidelines for Short Notice');
  const twoBidId = refId('WB e-Procurement System');

  const ins = db.prepare(
    `INSERT INTO rules
      (uid, ruleset_id, code, title, description, category, severity, rule_type, config, reference_id, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`
  );

  const rules = [
    {
      code: 'ADMIN_APPROVAL_REQUIRED',
      title: 'Administrative approval must be recorded',
      description: 'A tender/work must have administrative approval (number, date and authority) recorded before it can proceed to publication.',
      category: 'approval',
      severity: 'blocking',
      rule_type: 'approval_required',
      config: { field: 'admin_approval_no' },
      ref: null,
      sort: 10,
    },
    {
      code: 'TECH_SANCTION_REQUIRED',
      title: 'Technical sanction must be recorded',
      description: 'A work tender must have technical sanction (number, date, authority and approved amount) recorded before publication.',
      category: 'approval',
      severity: 'blocking',
      rule_type: 'approval_required',
      config: { field: 'tech_sanction_no' },
      ref: null,
      sort: 20,
    },
    {
      code: 'ESTIMATE_BOQ_REQUIRED',
      title: 'Estimate / BOQ must be present',
      description: 'The tender must have an estimate document attached, or a BOQ with at least one item.',
      category: 'document',
      severity: 'blocking',
      rule_type: 'document_required',
      config: { docKind: 'estimate', boqItemsSatisfy: true },
      ref: null,
      sort: 30,
    },
    {
      code: 'NOTICE_PERIOD_TIERS',
      title: 'Minimum notice period before bid closing',
      description: 'Minimum period from publication to bid submission: ≤₹10 lakh → 7 days; >₹10 lakh to ₹1 crore → 14 days; >₹1 crore → 21 days.',
      category: 'notice',
      severity: 'warning',
      rule_type: 'notice_period',
      config: {
        tiers: [
          { max_minor: 1000000 * 100, days: 7 },
          { max_minor: 10000000 * 100, days: 14 },
          { max_minor: null, days: 21 },
        ],
      },
      ref: purchasePolicyId,
      sort: 40,
    },
    {
      code: 'ETENDER_MANDATORY_WORKS_5L',
      title: 'e-Tender mandatory for works ≥ ₹5 lakh',
      description: 'For execution of works valued at ₹5 lakh and above, e-tendering through the centralized portal (https://wbtenders.gov.in) is mandatory.',
      category: 'award',
      severity: 'warning',
      rule_type: 'amount_threshold',
      config: { min_minor: 500000 * 100, procurement_category: 'works', required_tender_type: 'e-tender' },
      ref: rule177Id,
      sort: 50,
    },
    {
      code: 'ETENDER_SUPPLY_50L',
      title: 'e-Tender for supply valued ≥ ₹50 lakh',
      description: 'For supply of articles/stores with tender value ₹50 lakh and above, e-tendering through the centralized portal is mandatory.',
      category: 'award',
      severity: 'warning',
      rule_type: 'amount_threshold',
      config: { min_minor: 5000000 * 100, procurement_category: 'supply', required_tender_type: 'e-tender' },
      ref: purchasePolicyId,
      sort: 60,
    },
    {
      code: 'PUBLICATION_MANNER',
      title: 'Publication manner by estimated value',
      description: 'Publication requirements (notice board, website, newspapers, e-tender portal) vary by estimated value band.',
      category: 'notice',
      severity: 'info',
      rule_type: 'amount_threshold',
      config: {
        bands: [
          { max_minor: 100000 * 100, manner: 'Notice board + official website (off-line)' },
          { max_minor: 500000 * 100, manner: '+ one Bengali (Nepali in hill areas) newspaper' },
          { max_minor: 1000000 * 100, manner: '+ two newspapers (Bengali + English) + wbtenders.gov.in' },
          { max_minor: null, manner: '+ three newspapers (Bengali, English, Hindi) + official website + wbtenders.gov.in' },
        ],
      },
      ref: purchasePolicyId,
      sort: 70,
    },
    {
      code: 'SHORT_NOTICE_3DAY',
      title: 'Short-notice tender — minimum 3 days',
      description: 'Short-notice/emergent off-line tenders require a minimum notice of 3 days with wide publicity. Authorised only for emergent works.',
      category: 'notice',
      severity: 'verification_required',
      rule_type: 'field_required',
      config: { fields: ['emergency_reason'] },
      ref: shortNoticeId,
      sort: 80,
    },
    {
      code: 'RETENDER_LT3',
      title: 'Re-tender when response < 3 bidders',
      description: 'If the response to an e-tender is fewer than three bidders, the tender should be invited afresh (re-tender).',
      category: 'evaluation',
      severity: 'verification_required',
      rule_type: 'manual',
      config: {},
      ref: twoBidId,
      sort: 90,
    },
    {
      code: 'TWO_BID_SYSTEM',
      title: 'Two-bid system for e-tenders',
      description: 'e-Tenders follow a two-bid system: technical proposal opened first; financial bids opened only for technically qualified bidders.',
      category: 'evaluation',
      severity: 'verification_required',
      rule_type: 'manual',
      config: {},
      ref: twoBidId,
      sort: 100,
    },
  ];

  for (const r of rules) {
    ins.run(uuid(), rulesetId, r.code, r.title, r.description, r.category, r.severity, r.rule_type, json(r.config), r.ref, r.sort);
  }
  // eslint-disable-next-line no-console
  console.log('[seed] created default rules & compliance rule set');
}

function seedSchemesFunds() {
  const existing = db.get('SELECT COUNT(*) c FROM schemes').c;
  if (existing > 0) return;
  const schemes = [
    ['Fifteenth Finance Commission Grant', '15FC'],
    ['State Finance Commission Grant', 'SFC'],
    ['Own Fund', 'OWN'],
    ['MGNREGA', 'MGNREGA'],
    ['PM Awas Yojana (Gramin)', 'PMAY-G'],
    ['Other Scheme', 'OTHER'],
  ];
  const insS = db.prepare('INSERT INTO schemes (uid, name, code) VALUES (?,?,?)');
  for (const [name, code] of schemes) insS.run(uuid(), name, code);
  const funds = [
    ['15th FC — Tied Grant', '15FC-T', '15th Finance Commission', '2515'],
    ['15th FC — Untied Grant', '15FC-U', '15th Finance Commission', '2515'],
    ['SFC Grant', 'SFC-G', 'State Finance Commission', '2525'],
    ['Own Fund', 'OWN-F', 'Panchayat Own Fund', '0202'],
    ['Central Scheme Fund', 'CENT-F', 'Government of India', '3601'],
  ];
  const insF = db.prepare('INSERT INTO funds (uid, name, code, funding_source, head_of_account) VALUES (?,?,?,?,?)');
  for (const [name, code, source, hoa] of funds) insF.run(uuid(), name, code, source, hoa);
}

function seedTemplates() {
  const existing = db.get('SELECT COUNT(*) c FROM templates').c;
  if (existing > 0) return;
  const ins = db.prepare(
    `INSERT INTO templates (uid, code, name, doc_type, version, content_html, merge_fields, is_default)
     VALUES (?,?,?,?,?,?,?,1)`
  );

  const NIT = `
<div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:1.5">
  <p style="text-align:center"><strong>{{panchayat_name}}</strong><br/>
  {{panchayat_address}}<br/>
  Phone: {{panchayat_phone}} | Email: {{panchayat_email}}</p>
  <hr/>
  <h2 style="text-align:center;text-decoration:underline">NOTICE INVITING TENDER (NIT)</h2>
  <table width="100%" style="font-size:11pt">
    <tr><td><strong>NIT No.:</strong> {{nit_number}}</td><td style="text-align:right"><strong>Date:</strong> {{nit_date}}</td></tr>
    <tr><td><strong>Tender No.:</strong> {{tender_number}}</td><td style="text-align:right"><strong>Financial Year:</strong> {{fy_label}}</td></tr>
  </table>
  <p>Sealed bids are invited for the following work:</p>
  <table width="100%" border="1" cellpadding="6" style="border-collapse:collapse;font-size:11pt">
    <tr><td><strong>Name of Work</strong></td><td>{{work_name}}</td></tr>
    <tr><td><strong>Description</strong></td><td>{{description}}</td></tr>
    <tr><td><strong>Location</strong></td><td>{{location}}</td></tr>
    <tr><td><strong>Estimated Cost</strong></td><td>{{estimated_cost}}</td></tr>
    <tr><td><strong>EMD</strong></td><td>{{emd}}</td></tr>
    <tr><td><strong>Tender Fee</strong></td><td>{{tender_fee}}</td></tr>
    <tr><td><strong>Completion Period</strong></td><td>{{completion_period}}</td></tr>
    <tr><td><strong>Bid Start Date</strong></td><td>{{bid_start_date}}</td></tr>
    <tr><td><strong>Bid Closing Date</strong></td><td>{{bid_close_date}}</td></tr>
    <tr><td><strong>Technical Bid Opening</strong></td><td>{{technical_open_date}}</td></tr>
    <tr><td><strong>Financial Bid Opening</strong></td><td>{{financial_open_date}}</td></tr>
  </table>
  <h3>Eligibility</h3>
  <p>{{eligibility_notes}}</p>
  <h3>Conditions</h3>
  <p>{{special_conditions}}</p>
  <p style="margin-top:24pt"><strong>{{authority_name}}</strong><br/>{{authority_designation}}<br/>{{panchayat_name}}</p>
  <p style="font-size:8pt;color:#555;margin-top:24pt">
    Document generated by {{system_name}} on {{generated_at}}. Verification code: {{verification_code}}.
    This is a system-generated draft based on configured templates and is not an official Government of West Bengal document unless duly signed and issued by the competent authority.
  </p>
</div>`;

  const LOA = `
<div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:1.5">
  <p style="text-align:center"><strong>{{panchayat_name}}</strong><br/>{{panchayat_address}}</p>
  <hr/>
  <h2 style="text-align:center;text-decoration:underline">LETTER OF ACCEPTANCE (LOA)</h2>
  <p><strong>LOA No.:</strong> {{loa_number}} &nbsp;&nbsp; <strong>Date:</strong> {{loa_date}}</p>
  <p>To,<br/><strong>{{contractor_name}}</strong><br/>{{contractor_address}}</p>
  <p>Your bid for the work <strong>{{work_name}}</strong> (Tender No. {{tender_number}}) has been accepted at a total value of <strong>{{awarded_amount}}</strong>.</p>
  <p>The completion period is <strong>{{completion_period}}</strong> from the date of issue of the Work Order. Please submit the required security deposit of <strong>{{security_deposit}}</strong> and execute the agreement within the stipulated time.</p>
  <p style="margin-top:24pt"><strong>{{authority_name}}</strong><br/>{{authority_designation}}<br/>{{panchayat_name}}</p>
  <p style="font-size:8pt;color:#555;margin-top:24pt">Generated by {{system_name}} on {{generated_at}}. Verification code: {{verification_code}}. System-generated draft.</p>
</div>`;

  const WORK_ORDER = `
<div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:1.5">
  <p style="text-align:center"><strong>{{panchayat_name}}</strong><br/>{{panchayat_address}}</p>
  <hr/>
  <h2 style="text-align:center;text-decoration:underline">WORK ORDER</h2>
  <p><strong>Work Order No.:</strong> {{work_order_number}} &nbsp;&nbsp; <strong>Date:</strong> {{work_order_date}}</p>
  <p><strong>Tender No.:</strong> {{tender_number}} &nbsp;|&nbsp; <strong>Agreement No.:</strong> {{agreement_number}}</p>
  <p>To,<br/><strong>{{contractor_name}}</strong><br/>{{contractor_address}}</p>
  <p>You are hereby directed to commence the work <strong>{{work_name}}</strong> at <strong>{{location}}</strong> for a value of <strong>{{amount}}</strong>.</p>
  <p><strong>Start Date:</strong> {{start_date}} &nbsp;&nbsp; <strong>Completion Date:</strong> {{completion_date}}</p>
  <p>{{conditions}}</p>
  <p style="margin-top:24pt"><strong>{{authority_name}}</strong><br/>{{authority_designation}}<br/>{{panchayat_name}}</p>
  <p style="font-size:8pt;color:#555;margin-top:24pt">Generated by {{system_name}} on {{generated_at}}. Verification code: {{verification_code}}. System-generated draft.</p>
</div>`;

  const COMPLETION = `
<div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:1.5">
  <p style="text-align:center"><strong>{{panchayat_name}}</strong><br/>{{panchayat_address}}</p>
  <hr/>
  <h2 style="text-align:center;text-decoration:underline">COMPLETION CERTIFICATE</h2>
  <p><strong>Certificate No.:</strong> {{certificate_no}} &nbsp;&nbsp; <strong>Date:</strong> {{completion_date}}</p>
  <p>This is to certify that the work <strong>{{work_name}}</strong> under Tender No. {{tender_number}} awarded to <strong>{{contractor_name}}</strong> has been completed and inspected, and the final measurement and final bill have been processed.</p>
  <p><strong>Completion Date:</strong> {{completion_date}} &nbsp;&nbsp; <strong>Final Bill No.:</strong> {{final_bill_number}}</p>
  <p style="margin-top:24pt"><strong>{{authority_name}}</strong><br/>{{authority_designation}}<br/>{{panchayat_name}}</p>
  <p style="font-size:8pt;color:#555;margin-top:24pt">Generated by {{system_name}} on {{generated_at}}. Verification code: {{verification_code}}. System-generated draft.</p>
</div>`;

  const templates = [
    ['nit', 'Notice Inviting Tender (NIT)', 'nit', NIT],
    ['loa', 'Letter of Acceptance (LOA)', 'loa', LOA],
    ['work_order', 'Work Order', 'work_order', WORK_ORDER],
    ['completion_certificate', 'Completion Certificate', 'completion_certificate', COMPLETION],
  ];
  for (const [code, name, docType, html] of templates) {
    ins.run(uuid(), code, name, docType, 1, html, json([]));
  }
}

function seedAll() {
  seedPermissions();
  seedRoles();
  seedAdmin();
  seedPanchayat();
  seedFinancialYears();
  seedRuleReferences();
  seedDefaultRuleset();
  seedSchemesFunds();
  seedTemplates();
}

function reset() {
  db.getDb().exec('PRAGMA foreign_keys = OFF;');
  const tables = db
    .all(`SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'`)
    .map((r) => r.name);
  for (const t of tables) db.getDb().exec(`DROP TABLE IF EXISTS "${t}";`);
  db.getDb().exec('PRAGMA foreign_keys = ON;');
}

module.exports = { seedAll, reset, seedPermissions, seedRoles, seedAdmin, seedPanchayat, seedFinancialYears, seedRuleReferences, seedDefaultRuleset, seedSchemesFunds, seedTemplates };

if (require.main === module) {
  const { migrate } = require('./database');
  const migrations = require('./migrations');
  migrate(migrations);
  if (process.argv.includes('--reset')) {
    reset();
    migrate(migrations);
  }
  seedAll();
  // eslint-disable-next-line no-console
  console.log('[seed] completed.');
  db.closeDb();
}
