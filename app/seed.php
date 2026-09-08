<?php
/**
 * Seeder: permissions, roles, admin user, panchayat configuration, financial
 * years, rule references, base rule set, schemes/funds, and document templates.
 * Also provides a clearly-labelled [DEMO] lifecycle seed.
 *
 * Run from CLI:  php seed.php [--reset] [--demo] [--demo-reset]
 *                (also reachable via web: installer.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/documents.php';

/** Progress line for CLI runs; silent during web installs. */
function seed_log(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        echo $msg . "\n";
    }
}

// ---------------------------------------------------------------------------
// Static catalog (mirrors the Node permission/RBAC catalog)
// ---------------------------------------------------------------------------
function seed_permission_catalog(): array
{
    return [
        ['panchayat.view', 'View Panchayat configuration', 'panchayat'],
        ['panchayat.manage', 'Configure Panchayat', 'panchayat'],
        ['settings.manage', 'Manage system settings', 'settings'],
        ['user.view', 'View users', 'users'],
        ['user.manage', 'Create/edit users', 'users'],
        ['role.manage', 'Manage roles and permissions', 'users'],
        ['audit.view', 'View audit trail', 'audit'],
        ['audit.manage', 'Manage audit configuration', 'audit'],
        ['fy.view', 'View financial years', 'financial-year'],
        ['fy.manage', 'Create/open/close financial years', 'financial-year'],
        ['rules.view', 'View rules & references', 'rules'],
        ['rules.manage', 'Manage rules & references', 'rules'],
        ['scheme.view', 'View schemes/funds', 'master-data'],
        ['scheme.manage', 'Manage schemes/funds', 'master-data'],
        ['project.view', 'View projects', 'project'],
        ['project.manage', 'Create/edit projects', 'project'],
        ['plan.view', 'View procurement plans', 'project'],
        ['plan.manage', 'Manage procurement plans', 'project'],
        ['contractor.view', 'View contractors', 'contractor'],
        ['contractor.manage', 'Create/edit contractors', 'contractor'],
        ['contractor.sensitive', 'View contractor sensitive info (PAN/GST/bank)', 'contractor'],
        ['tender.view', 'View tenders', 'tender'],
        ['tender.manage', 'Create/edit tenders', 'tender'],
        ['tender.approve', 'Approve tender workflow', 'tender'],
        ['tender.publish', 'Publish tenders', 'tender'],
        ['tender.corrigendum', 'Issue corrigenda', 'tender'],
        ['tender.cancel', 'Cancel tenders', 'tender'],
        ['tender.retender', 'Create re-tenders', 'tender'],
        ['boq.manage', 'Manage BOQ', 'tender'],
        ['nit.manage', 'Generate/manage NIT', 'tender'],
        ['document.view', 'View documents', 'documents'],
        ['document.upload', 'Upload documents', 'documents'],
        ['document.download', 'Download documents', 'documents'],
        ['document.delete', 'Delete documents', 'documents'],
        ['bid.manage', 'Record/manage bidders', 'bids'],
        ['bid.view_confidential', 'View confidential bid info', 'bids'],
        ['technical_open.manage', 'Manage technical bid opening', 'bids'],
        ['technical_eval.manage', 'Perform technical evaluation', 'bids'],
        ['financial_open.manage', 'Manage financial bid opening', 'bids'],
        ['financial_eval.manage', 'Manage financial evaluation', 'bids'],
        ['comparative.view', 'View comparative statements', 'bids'],
        ['award.view', 'View awards/LOA/agreements/work orders', 'award'],
        ['award.manage', 'Manage award/LOA/agreement/work order', 'award'],
        ['award.approve', 'Approve awards', 'award'],
        ['execution.manage', 'Manage work execution/progress', 'execution'],
        ['measurement.manage', 'Manage measurements', 'execution'],
        ['measurement.approve', 'Approve measurements', 'execution'],
        ['bill.view', 'View bills', 'billing'],
        ['bill.manage', 'Manage bills', 'billing'],
        ['bill.approve', 'Approve/certify bills', 'billing'],
        ['payment.view', 'View payments', 'billing'],
        ['payment.manage', 'Record/manage payments', 'billing'],
        ['payment.approve', 'Approve payments', 'billing'],
        ['completion.manage', 'Manage completion/closing', 'execution'],
        ['report.view', 'View reports', 'reports'],
        ['report.export', 'Export reports (PDF/Excel)', 'reports'],
        ['public.view', 'View public portal data', 'public'],
        ['import.manage', 'Import data (Excel/CSV)', 'system'],
        ['backup.manage', 'Manage backups', 'system'],
        ['template.manage', 'Manage document templates', 'system'],
        ['notification.view', 'View notifications', 'system'],
    ];
}

function seed_role_defs(): array
{
    return [
        ['super_admin', 'Super Admin', 'System-wide administrator (all permissions).', true, true, []],
        ['panchayat_admin', 'Panchayat Admin', 'Administers a single Panchayat: configuration, users, rules, data.', true, false,
            ['panchayat.view','panchayat.manage','settings.manage','user.view','user.manage','fy.view','fy.manage','rules.view','rules.manage','scheme.view','scheme.manage','project.view','project.manage','plan.view','plan.manage','contractor.view','contractor.manage','contractor.sensitive','tender.view','tender.manage','tender.publish','tender.corrigendum','tender.cancel','tender.retender','boq.manage','nit.manage','document.view','document.upload','document.download','bid.manage','bid.view_confidential','technical_open.manage','report.view','report.export','import.manage','backup.manage','template.manage','notification.view']],
        ['pradhan', 'Pradhan', 'Elected head — approvals, oversight.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','plan.view','contractor.view','tender.view','tender.approve','award.view','award.approve','document.view','document.download','bid.view_confidential','comparative.view','bill.view','bill.approve','payment.view','payment.approve','report.view','report.export','notification.view']],
        ['upa_pradhan', 'Upa-Pradhan', 'Deputy head — approvals when delegated.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','contractor.view','tender.view','tender.approve','document.view','document.download','comparative.view','bill.view','bill.approve','payment.view','report.view','report.export','notification.view']],
        ['panchayat_secretary', 'Panchayat Secretary', 'Secretary — day-to-day records, submissions, workflow.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','scheme.manage','project.view','project.manage','plan.view','plan.manage','contractor.view','contractor.manage','tender.view','tender.manage','tender.approve','tender.publish','tender.corrigendum','tender.retender','boq.manage','nit.manage','document.view','document.upload','document.download','bid.manage','bid.view_confidential','technical_open.manage','financial_open.manage','technical_eval.manage','financial_eval.manage','comparative.view','award.manage','execution.manage','measurement.manage','measurement.approve','bill.manage','bill.approve','payment.manage','completion.manage','report.view','report.export','import.manage','notification.view']],
        ['technical_officer', 'Technical Officer / Engineer', 'Engineer — estimates, BOQ, measurements, execution.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','project.manage','plan.view','contractor.view','tender.view','tender.manage','boq.manage','nit.manage','document.view','document.upload','document.download','bid.manage','technical_open.manage','technical_eval.manage','comparative.view','execution.manage','measurement.manage','measurement.approve','bill.view','bill.manage','completion.manage','report.view','report.export','notification.view']],
        ['accounts_officer', 'Accounts Officer', 'Accounts — bills, payments, reconciliation.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','contractor.view','contractor.sensitive','tender.view','comparative.view','document.view','document.download','bill.view','bill.manage','bill.approve','payment.manage','payment.approve','report.view','report.export','notification.view']],
        ['tender_committee', 'Tender Committee Member', 'Tender committee — evaluation and recommendation.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','contractor.view','tender.view','document.view','document.download','bid.view_confidential','technical_open.manage','technical_eval.manage','financial_open.manage','financial_eval.manage','comparative.view','award.manage','report.view','notification.view']],
        ['data_entry', 'Data Entry Operator', 'Data entry only — no approvals, no financial finalization.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','project.manage','contractor.view','contractor.manage','tender.view','tender.manage','boq.manage','document.view','document.upload','bid.manage','report.view','notification.view']],
        ['auditor', 'Auditor', 'Read-only across domains plus audit access.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','plan.view','contractor.view','tender.view','comparative.view','document.view','document.download','audit.view','bill.view','payment.view','report.view','report.export','notification.view']],
        ['viewer', 'Viewer', 'Read-only viewer.', true, false,
            ['panchayat.view','fy.view','rules.view','scheme.view','project.view','contractor.view','tender.view','document.view','report.view','notification.view']],
    ];
}

// ---------------------------------------------------------------------------
// Seed functions (all idempotent)
// ---------------------------------------------------------------------------
function seed_permissions(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM permissions') > 0) return;
    foreach (seed_permission_catalog() as [$code, $name, $category]) {
        DB::insert('INSERT INTO permissions (code, name, category) VALUES (?,?,?)', [$code, $name, $category]);
    }
}

function seed_roles(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM roles') > 0) return;
    $permIds = [];
    foreach (DB::all('SELECT id, code FROM permissions') as $p) {
        $permIds[$p['code']] = (int) $p['id'];
    }
    foreach (seed_role_defs() as [$code, $name, $desc, $system, $allPerms, $perms]) {
        $roleId = DB::insert('INSERT INTO roles (uid, code, name, description, is_system) VALUES (?,?,?,?,?)', [uid(), $code, $name, $desc, $system ? 1 : 0]);
        if ($allPerms) {
            foreach ($permIds as $pid) {
                DB::insert('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$roleId, $pid]);
            }
        } else {
            foreach ($perms as $code) {
                if (isset($permIds[$code])) {
                    DB::insert('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$roleId, $permIds[$code]]);
                }
            }
        }
    }
}

function seed_admin(?array $admin = null): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM users') > 0) return;
    $email = strtolower(trim((string) ($admin['email'] ?? SEED_ADMIN_EMAIL ?: 'admin@example.com')));
    $name = trim((string) ($admin['name'] ?? 'System Administrator')) ?: 'System Administrator';
    $pass = (string) ($admin['password'] ?? SEED_ADMIN_PASSWORD);
    if ($pass === '') {
        if (PHP_SAPI === 'cli') {
            $pass = bin2hex(random_bytes(6)) . '@A1';
            seed_log('[seed] generated one-time admin password: ' . $pass . ' (change immediately)');
        } else {
            throw validation('Create the administrator password in the installer; no default production password is shipped.');
        }
    }
    if (strlen($pass) < 10 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
        throw validation('Administrator password must be at least 10 characters and include upper-case, lower-case and a number.');
    }
    $userId = DB::insert(
        'INSERT INTO users (uid, panchayat_id, email, password_hash, name, designation, is_global_admin, is_active) VALUES (?,?,?,?,?,?,1,1)',
        [uid(), null, $email, password_hash_secure($pass), $name, 'Super Administrator']
    );
    $role = DB::one("SELECT id FROM roles WHERE code = 'super_admin'");
    DB::insert('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$userId, (int) $role['id']]);
    seed_log("[seed] created global admin: $email");
}

function seed_panchayat(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM panchayats') > 0) return;
    DB::insert(
        'INSERT INTO panchayats (uid, state, district, block, gram_panchayat, gp_code, office_address, pin, phone, email,
           pradhan, upa_pradhan, panchayat_secretary, technical_officer, accounts_officer)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [uid(), 'West Bengal', 'Paschim Bardhaman', 'Sample Block', 'Sample Gram Panchayat', 'GP-SAMPLE-001',
         'Village & PO — Sample, Dist. Paschim Bardhaman, West Bengal', '713301', '0341-0000000', 'gp.sample@wb.gov.in',
         'Pradhan, Sample GP', 'Upa-Pradhan, Sample GP', 'Secretary, Sample GP', 'Technical Officer, Sample GP', 'Accounts Officer, Sample GP']
    );
    seed_log("[seed] created sample Panchayat configuration");
}

function seed_financial_years(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM financial_years') > 0) return;
    $years = [
        ['2024-25', 2024, '2024-04-01', '2025-03-31', 0],
        ['2025-26', 2025, '2025-04-01', '2026-03-31', 0],
        ['2026-27', 2026, '2026-04-01', '2027-03-31', 1],
        ['2027-28', 2027, '2027-04-01', '2028-03-31', 0],
    ];
    foreach ($years as [$label, $sy, $start, $end, $current]) {
        DB::insert('INSERT INTO financial_years (uid, label, start_year, start_date, end_date, status, is_current) VALUES (?,?,?,?,?,?,?)',
            [uid(), $label, $sy, $start, $end, 'open', $current]);
    }
}

function seed_rule_references(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM rule_references') > 0) return;
    $refs = [
        ['New Purchase Policy of West Bengal Government', 'Government of West Bengal, Finance Department (Audit Branch)', 'W.B. Financial Rules / Purchase Policy', 'No. 5400-F(Y)', '2012-06-25', '2012-06-25', '', 'VERIFICATION REQUIRED', 'Reference summary is included for configurable system checks only. Verify the current official GoWB Finance/P&RD source before relying on this rule.'],
        ['Amendment of Rule 177 of WBFR regarding Tenders', 'Government of West Bengal, Finance Department', 'W.B. Financial Rules', 'WBFR Rule 177 (as amended)', '2014-04-27', '2014-04-27', '', 'VERIFICATION REQUIRED', 'Reference summary is included for configurable system checks only. Verify the current official GoWB Finance/P&RD source before relying on this rule.'],
        ['Guidelines for Short Notice Tender in Emergent Situations', 'Government of West Bengal, Irrigation & Waterways Directorate', 'I&WD Guidelines', 'Notification No. 5400-F(Y) dated 25.06.2012 / WBFR Rule 47(8)', '2012-06-25', '2012-06-25', '', 'VERIFICATION REQUIRED', 'Reference summary is included for configurable system checks only. Verify current official circulars and GP applicability before relying on this rule.'],
        ['WB e-Procurement System (two-bid)', 'Government of West Bengal — eProcurement System', 'e-NIT General Terms & Conditions', 'wbtenders.gov.in', null, null, 'https://wbtenders.gov.in/nicgep/app', 'current', 'e-Tenders use a two-bid system (technical + financial). Re-tender when response is fewer than three bidders. Department-specific; verify GP applicability.'],
        ['P&RD Department — Tenders', 'Department of Panchayats & Rural Development, Government of West Bengal', 'P&RD tender listings', 'prd.wb.gov.in/tenders', null, null, 'https://prd.wb.gov.in/tenders/list', 'current', 'Official P&RD tender listings. Consult for GP-specific tender conditions and formats.'],
    ];
    foreach ($refs as [$title, $authority, $manual, $refno, $pub, $eff, $url, $ver, $notes]) {
        DB::insert(
            'INSERT INTO rule_references (uid, title, authority, manual_name, reference_number, publication_date, effective_date, source_url, version, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [uid(), $title, $authority, $manual, $refno, $pub, $eff, $url, $ver, $notes]
        );
    }
}

function seed_default_ruleset(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM rulesets') > 0) return;
    $rulesetId = DB::insert(
        'INSERT INTO rulesets (uid, name, issuing_authority, jurisdiction, effective_date, reference_number, source_url, version, is_active, notes)
         VALUES (?,?,?,?,?,?,?,1,1,?)',
        [uid(), 'WB Gram Panchayat Procurement — Base Rule Set (Verification Required)', 'Government of West Bengal (Finance Dept. / P&RD Dept.)', 'West Bengal',
         '2012-06-25', 'WBFR + FD No. 5400-F(Y) dt 25.06.2012', 'https://prd.wb.gov.in/',
         'This configurable starter rule set is provided for system checks only and is not legal advice. Rules marked VERIFICATION REQUIRED must be verified against current official GoWB/P&RD/Finance circulars before operational reliance.']
    );
    $refId = function (string $needle) {
        $r = DB::one('SELECT id FROM rule_references WHERE title LIKE ?', ['%' . $needle . '%']);
        return $r ? (int) $r['id'] : null;
    };
    $purchasePolicyId = $refId('New Purchase Policy');
    $rule177Id = $refId('Amendment of Rule 177');
    $shortNoticeId = $refId('Guidelines for Short Notice');
    $twoBidId = $refId('WB e-Procurement System');

    $rules = [
        ['ADMIN_APPROVAL_REQUIRED', 'Administrative approval must be recorded', 'A tender/work must have administrative approval (number, date and authority) recorded before it can proceed to publication.', 'approval', 'blocking', 'approval_required', ['field' => 'admin_approval_no'], null, 10],
        ['TECH_SANCTION_REQUIRED', 'Technical sanction must be recorded', 'A work tender must have technical sanction (number, date, authority and approved amount) recorded before publication.', 'approval', 'blocking', 'approval_required', ['field' => 'tech_sanction_no'], null, 20],
        ['ESTIMATE_BOQ_REQUIRED', 'Estimate / BOQ must be present', 'The tender must have an estimate document attached, or a BOQ with at least one item.', 'document', 'blocking', 'document_required', ['docKind' => 'estimate', 'boqItemsSatisfy' => true], null, 30],
        ['NOTICE_PERIOD_TIERS', 'Minimum notice period before bid closing', 'Minimum period from publication to bid submission: ≤₹10 lakh → 7 days; >₹10 lakh to ₹1 crore → 14 days; >₹1 crore → 21 days.', 'notice', 'warning', 'notice_period', ['tiers' => [
            ['max_minor' => 100000000, 'days' => 7],
            ['max_minor' => 1000000000, 'days' => 14],
            ['max_minor' => null, 'days' => 21],
        ]], $purchasePolicyId, 40],
        ['ETENDER_MANDATORY_WORKS_5L', 'e-Tender mandatory for works ≥ ₹5 lakh', 'For execution of works valued at ₹5 lakh and above, e-tendering through the centralized portal (https://wbtenders.gov.in) is mandatory.', 'award', 'warning', 'amount_threshold', ['min_minor' => 50000000, 'procurement_category' => 'works', 'required_tender_type' => 'e-tender'], $rule177Id, 50],
        ['ETENDER_SUPPLY_50L', 'e-Tender for supply valued ≥ ₹50 lakh', 'For supply of articles/stores with tender value ₹50 lakh and above, e-tendering through the centralized portal is mandatory.', 'award', 'warning', 'amount_threshold', ['min_minor' => 500000000, 'procurement_category' => 'supply', 'required_tender_type' => 'e-tender'], $purchasePolicyId, 60],
        ['PUBLICATION_MANNER', 'Publication manner by estimated value', 'Publication requirements (notice board, website, newspapers, e-tender portal) vary by estimated value band.', 'notice', 'info', 'amount_threshold', ['bands' => [
            ['max_minor' => 10000000, 'manner' => 'Notice board + official website (off-line)'],
            ['max_minor' => 50000000, 'manner' => '+ one Bengali (Nepali in hill areas) newspaper'],
            ['max_minor' => 100000000, 'manner' => '+ two newspapers (Bengali + English) + wbtenders.gov.in'],
            ['max_minor' => null, 'manner' => '+ three newspapers (Bengali, English, Hindi) + official website + wbtenders.gov.in'],
        ]], $purchasePolicyId, 70],
        ['SHORT_NOTICE_3DAY', 'Short-notice tender — minimum 3 days', 'Short-notice/emergent off-line tenders require a minimum notice of 3 days with wide publicity. Authorised only for emergent works.', 'notice', 'verification_required', 'field_required', ['fields' => ['emergency_reason'], 'when' => ['tender_type' => 'short-notice']], $shortNoticeId, 80],
        ['RETENDER_LT3', 'Re-tender when response < 3 bidders', 'If the response to an e-tender is fewer than three bidders, the tender should be invited afresh (re-tender).', 'evaluation', 'verification_required', 'manual', ['when' => ['tender_type' => 'e-tender']], $twoBidId, 90],
        ['TWO_BID_SYSTEM', 'Two-bid system for e-tenders', 'e-Tenders follow a two-bid system: technical proposal opened first; financial bids opened only for technically qualified bidders.', 'evaluation', 'verification_required', 'manual', ['when' => ['tender_type' => 'e-tender']], $twoBidId, 100],
    ];
    foreach ($rules as [$code, $title, $desc, $cat, $sev, $type, $config, $ref, $sort]) {
        DB::insert(
            'INSERT INTO rules (uid, ruleset_id, code, title, description, category, severity, rule_type, config, reference_id, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [uid(), $rulesetId, $code, $title, $desc, $cat, $sev, $type, json_store($config), $ref, $sort]
        );
    }
    seed_log("[seed] created default rules & compliance rule set");
}

function seed_schemes_funds(): void
{
    if ((int) DB::val('SELECT COUNT(*) FROM schemes') === 0) {
        foreach ([
            ['Fifteenth Finance Commission Grant', '15FC'],
            ['State Finance Commission Grant', 'SFC'],
            ['Own Fund', 'OWN'],
            ['MGNREGA', 'MGNREGA'],
            ['PM Awas Yojana (Gramin)', 'PMAY-G'],
            ['Other Scheme', 'OTHER'],
        ] as [$name, $code]) {
            DB::insert('INSERT INTO schemes (uid, name, code) VALUES (?,?,?)', [uid(), $name, $code]);
        }
    }
    if ((int) DB::val('SELECT COUNT(*) FROM funds') === 0) {
        foreach ([
            ['15th FC — Tied Grant', '15FC-T', '15th Finance Commission', '2515'],
            ['15th FC — Untied Grant', '15FC-U', '15th Finance Commission', '2515'],
            ['SFC Grant', 'SFC-G', 'State Finance Commission', '2525'],
            ['Own Fund', 'OWN-F', 'Panchayat Own Fund', '0202'],
            ['Central Scheme Fund', 'CENT-F', 'Government of India', '3601'],
        ] as [$name, $code, $source, $hoa]) {
            DB::insert('INSERT INTO funds (uid, name, code, funding_source, head_of_account) VALUES (?,?,?,?,?)', [uid(), $name, $code, $source, $hoa]);
        }
    }
}

function seed_templates(): void
{
    $templates = [
        ['nit', 'Notice Inviting Tender (NIT)', 'nit', template_nit_html()],
        ['loa', 'Letter of Acceptance (LOA)', 'loa', template_loa_html()],
        ['agreement', 'Agreement', 'agreement', template_agreement_html()],
        ['work_order', 'Work Order', 'work_order', template_work_order_html()],
        ['completion_certificate', 'Completion Certificate', 'completion_certificate', template_completion_html()],
    ];
    foreach ($templates as [$code, $name, $docType, $html]) {
        if (DB::one('SELECT id FROM templates WHERE code = ? AND version = 1', [$code])) { continue; }
        DB::insert(
            'INSERT INTO templates (uid, code, name, doc_type, version, content_html, merge_fields, is_default) VALUES (?,?,?,?,1,?,?,1)',
            [uid(), $code, $name, $docType, $html, json_store([])]
        );
    }
}

function seed_settings(): void
{
    $defaults = [
        'numbering.prefix' => 'GP',
        'numbering.tender_nit.pattern' => '{PREFIX}/NIT/{FY}/{NNN}',
        'numbering.bill.pattern' => 'BILL/{FY}/{NNN}',
        'numbering.work_order.pattern' => 'WO/{FY}/{NNN}',
        'numbering.agreement.pattern' => 'AGR/{FY}/{NNN}',
        'numbering.loa.pattern' => 'LOA/{FY}/{NNN}',
        'contractor.expiry_warning_days' => '60',
    ];
    foreach ($defaults as $k => $v) {
        if (!DB::one('SELECT id FROM settings WHERE ' . sql_ident('key') . ' = ?', [$k])) {
            DB::insert('INSERT INTO settings (' . sql_ident('key') . ', ' . sql_ident('value') . ') VALUES (?,?)', [$k, $v]);
        }
    }
}

function seed_all(bool $reset = false, ?array $admin = null): void
{
    if ($reset) {
        create_tables(true);
    } elseif (!schema_installed()) {
        create_tables(false);
    }
    seed_permissions();
    seed_roles();
    seed_admin($admin);
    seed_panchayat();
    seed_financial_years();
    seed_rule_references();
    seed_default_ruleset();
    seed_schemes_funds();
    seed_templates();
    seed_settings();
    if (function_exists('schema_backfill_panchayat_ids')) { schema_backfill_panchayat_ids(); }
    schema_stamp();
}

// ---------------------------------------------------------------------------
// Templates (stored as versioned rows in the `templates` table)
// ---------------------------------------------------------------------------
function template_nit_html(): string
{
    return <<<'HTML'
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
</div>
HTML;
}

function template_loa_html(): string
{
    return <<<'HTML'
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
</div>
HTML;
}

function template_agreement_html(): string
{
    return <<<'HTML'
<div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:1.5">
  <p style="text-align:center"><strong>{{panchayat_name}}</strong><br/>{{panchayat_address}}</p>
  <hr/>
  <h2 style="text-align:center;text-decoration:underline">AGREEMENT</h2>
  <p><strong>Agreement No.:</strong> {{agreement_number}} &nbsp;&nbsp; <strong>Date:</strong> {{agreement_date}}</p>
  <p>This agreement records the terms for <strong>{{work_name}}</strong> (Tender No. {{tender_number}}) with <strong>{{contractor_name}}</strong>.</p>
  <p><strong>Agreement amount:</strong> {{amount}} &nbsp;&nbsp; <strong>Completion period:</strong> {{completion_period}}</p>
  <p><strong>Security deposit:</strong> {{security_deposit}}</p>
  <p>{{conditions}}</p>
  <p style="margin-top:24pt"><strong>{{authority_name}}</strong><br/>{{authority_designation}}<br/>{{panchayat_name}}</p>
  <p style="font-size:8pt;color:#555;margin-top:24pt">Generated by {{system_name}} on {{generated_at}}. Verification code: {{verification_code}}. System-generated draft.</p>
</div>
HTML;
}

function template_work_order_html(): string
{
    return <<<'HTML'
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
</div>
HTML;
}

function template_completion_html(): string
{
    return <<<'HTML'
<div style="font-family:'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:1.5">
  <p style="text-align:center"><strong>{{panchayat_name}}</strong><br/>{{panchayat_address}}</p>
  <hr/>
  <h2 style="text-align:center;text-decoration:underline">COMPLETION CERTIFICATE</h2>
  <p><strong>Certificate No.:</strong> {{certificate_no}} &nbsp;&nbsp; <strong>Date:</strong> {{completion_date}}</p>
  <p>This is to certify that the work <strong>{{work_name}}</strong> under Tender No. {{tender_number}} awarded to <strong>{{contractor_name}}</strong> has been completed and inspected, and the final measurement and final bill have been processed.</p>
  <p><strong>Completion Date:</strong> {{completion_date}} &nbsp;&nbsp; <strong>Final Bill No.:</strong> {{final_bill_number}}</p>
  <p style="margin-top:24pt"><strong>{{authority_name}}</strong><br/>{{authority_designation}}<br/>{{panchayat_name}}</p>
  <p style="font-size:8pt;color:#555;margin-top:24pt">Generated by {{system_name}} on {{generated_at}}. Verification code: {{verification_code}}. System-generated draft.</p>
</div>
HTML;
}

// ---------------------------------------------------------------------------
// [DEMO] lifecycle seed (clearly labelled sample data)
// ---------------------------------------------------------------------------
function demo_is_seeded(): bool
{
    return (bool) DB::one('SELECT id FROM settings WHERE ' . sql_ident('key') . " = 'demo.seeded'");
}

function demo_seed(): array
{
    if (demo_is_seeded()) {
        return ['seeded' => false, 'reason' => 'already-seeded'];
    }
    $admin = DB::one('SELECT * FROM users WHERE is_global_admin = 1');
    if (!$admin) {
        throw err(500, 'SEED_MISSING_ADMIN', 'Admin user required for demo seed');
    }
    $fy = DB::one("SELECT * FROM financial_years WHERE label = '2026-27'");
    $actor = $admin;
    $demoPanchayatId = actor_panchayat_id($actor);

    $c1 = contractor_create(['legal_name' => '[DEMO] Sample Contractor Alpha', 'business_name' => 'Demo Firm', 'registration_class' => 'Class I', 'pan' => 'DEMOP1234A'], $actor);
    $c2 = contractor_create(['legal_name' => '[DEMO] Sample Contractor Beta', 'registration_class' => 'Class II'], $actor);

    $projectId = DB::insert(
        'INSERT INTO projects (uid, panchayat_id, fy_id, scheme_id, fund_id, work_name, description, location,
           administrative_approval_no, administrative_approval_date, technical_sanction_no, technical_sanction_date,
           estimate_amount_minor, sanctioned_amount_minor, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [uid(), $demoPanchayatId, (int) $fy['id'], 1, 1, '[DEMO] Construction of CC road (sample data)', 'Demo project for testing the full lifecycle', 'Demo Mouza',
         'DEMO-AA-001', '2026-06-01', 'DEMO-TS-001', '2026-06-05', 100000000, 115000000, 'planned', (int) $actor['id']]
    );

    $tender = tender_create(['fy_id' => (int) $fy['id'], 'procurement_category' => 'works', 'tender_type' => 'open', 'ruleset_id' => 1,
        'project_id' => $projectId, 'scheme_id' => 1, 'fund_id' => 1,
        'work_name' => '[DEMO] Construction of CC road (sample data)', 'title' => 'CC road', 'location' => 'Demo Mouza'], $actor);

    tender_update((int) $tender['id'], [
        'admin_approval_no' => 'DEMO-AA-001', 'admin_approval_date' => '2026-06-01', 'admin_approval_authority' => 'Pradhan (DEMO)',
        'tech_sanction_no' => 'DEMO-TS-001', 'tech_sanction_date' => '2026-06-05', 'tech_sanction_authority' => 'EO (DEMO)',
        'estimated_cost_minor' => 100000000, 'tender_value_minor' => 100000000, 'emd_minor' => 2000000, 'tender_fee_minor' => 50000,
        'completion_period_days' => 90, 'publication_date' => '2026-07-01', 'bid_close_date' => '2026-07-15',
        'technical_open_date' => '2026-07-16', 'financial_open_date' => '2026-07-18',
    ], $actor);

    foreach ([[1, 'Earthwork excavation', 'All kinds of soil', 'cum', 100, 25000], [2, 'CC M20 concrete', '1:1.5:3', 'cum', 50, 550000]] as [$itemNo, $desc, $spec, $unit, $qty, $rate]) {
        DB::insert('INSERT INTO boq_items (uid, panchayat_id, tender_id, item_no, description, specification, unit, quantity, estimated_rate_minor) VALUES (?,?,?,?,?,?,?,?,?)',
            [uid(), $demoPanchayatId, (int) $tender['id'], (string) $itemNo, $desc, $spec, $unit, $qty, $rate]);
    }

    tender_run_compliance((int) $tender['id'], $actor);
    tender_submit((int) $tender['id'], $actor);
    for ($i = 0; $i < 5; $i++) {
        tender_workflow_action((int) $tender['id'], 'approve', 'DEMO approval', $actor);
    }
    tender_generate_nit((int) $tender['id'], $actor);
    tender_publish((int) $tender['id'], ['publication_date' => '2026-07-01'], $actor);
    tender_start_bidding((int) $tender['id'], $actor);

    $b1 = bidder_add((int) $tender['id'], ['contractor_id' => (int) $c1['id']], $actor);
    $b2 = bidder_add((int) $tender['id'], ['contractor_id' => (int) $c2['id']], $actor);
    tender_close_bids((int) $tender['id'], $actor);
    bidder_technical_open((int) $tender['id'], ['opening_date' => '2026-07-16'], $actor);

    $k1 = criteria_add((int) $tender['id'], ['code' => 'REG', 'criterion' => 'Valid Registration'], );
    $k2 = criteria_add((int) $tender['id'], ['code' => 'EXP', 'criterion' => 'Similar Work Experience'], );
    evaluation_set((int) $tender['id'], (int) $b1['id'], (int) $k1['id'], ['result' => 'pass'], $actor);
    evaluation_set((int) $tender['id'], (int) $b1['id'], (int) $k2['id'], ['result' => 'pass'], $actor);
    evaluation_set((int) $tender['id'], (int) $b2['id'], (int) $k1['id'], ['result' => 'pass'], $actor);
    evaluation_set((int) $tender['id'], (int) $b2['id'], (int) $k2['id'], ['result' => 'fail', 'remarks' => 'No experience'], $actor);
    evaluation_finalize((int) $tender['id'], ['rejection_reasons' => [(int) $b2['id'] => 'No similar work experience (DEMO)']], $actor);

    financial_bid_record((int) $tender['id'], (int) $b1['id'], ['total_amount_minor' => 95000000], $actor);
    ranking_compute((int) $tender['id'], $actor);

    $award = award_recommend((int) $tender['id'], ['contractor_id' => (int) $c1['id'], 'awarded_amount_minor' => 95000000], $actor);
    award_approve((int) $award['id'], ['authority' => 'Pradhan (DEMO)'], $actor);
    award_issue_loa((int) $award['id'], $actor);
    award_create_agreement((int) $award['id'], ['execution_date' => '2026-07-25'], $actor);
    award_issue_work_order((int) $award['id'], ['start_date' => '2026-08-01', 'completion_date' => '2026-10-29'], $actor);
    document_generate_work_order((int) $award['id'], $actor);

    execution_record_progress($projectId, ['progress_date' => '2026-08-15', 'physical_progress' => 40, 'financial_progress' => 30], $actor);
    $m = measurement_create($projectId, ['measurement_date' => '2026-08-20'], $actor);
    $boq1 = (int) DB::val("SELECT id FROM boq_items WHERE tender_id = ? AND item_no = '1'", [(int) $tender['id']]);
    measurement_add_item((int) $m['id'], ['boq_item_id' => $boq1, 'item_no' => '1', 'description' => 'Earthwork excavation', 'unit' => 'cum', 'current_quantity' => 40, 'rate_minor' => 25000], $actor);
    measurement_set_status((int) $m['id'], 'checked', $actor);
    measurement_set_status((int) $m['id'], 'approved', $actor);

    $bill = bill_create($projectId, ['bill_type' => 'running'], $actor);
    bill_update((int) $bill['id'], ['grossWorkValueMinor' => 1000000, 'retentionPct' => 5, 'deductionsMinor' => 0, 'recoveriesMinor' => 0], $actor);
    bill_submit((int) $bill['id'], $actor);
    for ($i = 0; $i < 4; $i++) {
        bill_workflow_action((int) $bill['id'], 'approve', 'DEMO bill approval', $actor);
    }
    $pay = payment_record((int) $bill['id'], ['net_amount_minor' => 950000, 'payment_method' => 'bank_transfer', 'transaction_reference' => 'DEMO-TXN-0001'], $actor);
    completion_advance($projectId, ['status' => 'inspection_done', 'date' => '2026-10-30'], $actor);
    completion_advance($projectId, ['status' => 'final_measurement_done', 'final_measurement_id' => (int) $m['id']], $actor);
    completion_advance($projectId, ['status' => 'final_bill_done', 'final_bill_id' => (int) $bill['id']], $actor);
    completion_advance($projectId, ['status' => 'final_payment_done', 'final_payment_id' => (int) $pay['id']], $actor);
    completion_advance($projectId, ['status' => 'security_released', 'date' => '2026-11-15'], $actor);
    completion_advance($projectId, ['status' => 'closed', 'date' => '2026-11-20'], $actor);
    document_generate_completion($projectId, $actor);

    if (DB::one('SELECT id FROM settings WHERE ' . sql_ident('key') . " = 'demo.seeded'")) {
        DB::run('UPDATE settings SET ' . sql_ident('value') . " = '1' WHERE " . sql_ident('key') . " = 'demo.seeded'");
    } else {
        DB::insert('INSERT INTO settings (' . sql_ident('key') . ', ' . sql_ident('value') . ") VALUES ('demo.seeded','1')");
    }
    return [
        'seeded' => true,
        'tender' => (string) DB::val('SELECT tender_number FROM tenders WHERE id = ?', [(int) $tender['id']]),
        'projectId' => $projectId,
        'contractors' => [$c1['legal_name'], $c2['legal_name']],
        'paymentVoucher' => $pay['voucher_no'],
    ];
}

function demo_reset(): array
{
    DB::run('DELETE FROM settings WHERE ' . sql_ident('key') . " = 'demo.seeded'");
    $demoProjects = DB::all("SELECT id FROM projects WHERE work_name LIKE '[DEMO]%'");
    foreach ($demoProjects as $p) {
        $pid = (int) $p['id'];
        DB::run('DELETE FROM payments WHERE project_id = ?', [$pid]);
        DB::run('DELETE FROM bill_items WHERE bill_id IN (SELECT id FROM bills WHERE project_id = ?)', [$pid]);
        DB::run('DELETE FROM bills WHERE project_id = ?', [$pid]);
        DB::run('DELETE FROM measurement_items WHERE measurement_id IN (SELECT id FROM measurements WHERE project_id = ?)', [$pid]);
        DB::run('DELETE FROM measurements WHERE project_id = ?', [$pid]);
        DB::run('DELETE FROM work_progress WHERE project_id = ?', [$pid]);
        DB::run('DELETE FROM completions WHERE project_id = ?', [$pid]);
    }
    DB::run("DELETE FROM tenders WHERE work_name LIKE '[DEMO]%'");
    DB::run("DELETE FROM projects WHERE work_name LIKE '[DEMO]%'");
    DB::run("DELETE FROM contractors WHERE legal_name LIKE '[DEMO]%'");
    return ['reset' => true];
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $args = $GLOBALS['argv'] ?? [];
    if (in_array('--demo-reset', $args, true)) {
        echo json_encode(demo_reset(), JSON_PRETTY_PRINT) . "\n";
        exit;
    }
    if (in_array('--heal', $args, true)) {
        echo json_encode(schema_heal(), JSON_PRETTY_PRINT) . "\n";
        exit;
    }
    if (in_array('--check', $args, true)) {
        $h = schema_health();
        echo "schema_installed: " . (schema_installed() ? 'yes' : 'no') . "\n";
        echo "schema_uptodate:  " . (schema_uptodate() ? 'yes' : 'no') . "\n";
        echo "missing tables:   " . (count($h['missing_tables']) ? implode(', ', $h['missing_tables']) : 'none') . "\n";
        $count = 0;
        foreach ($h['missing_columns'] as $t => $cols) {
            $count += count($cols);
            echo "missing columns:  $t → " . implode(', ', $cols) . "\n";
        }
        if ($count === 0) {
            echo "missing columns:  none\n";
        }
        exit(($h['missing_tables'] || $h['missing_columns']) ? 1 : 0);
    }
    seed_all(in_array('--reset', $args, true));
    if (in_array('--demo', $args, true)) {
        echo json_encode(demo_seed(), JSON_PRETTY_PRINT) . "\n";
    }
    echo "[seed] completed.\n";
}
