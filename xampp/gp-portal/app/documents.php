<?php
/**
 * Document generation (system-generated drafts).
 *
 * Renders printable HTML drafts (NIT, LOA, Work Order, Completion Certificate)
 * from live database records, stores them in the file system and registers a
 * verification code. Every draft carries a "system-generated draft — not an
 * official document" notice. No digital signature or e-procurement sync is
 * faked.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit.php';

function panchayat_context(): array
{
    return DB::one('SELECT * FROM panchayats ORDER BY id LIMIT 1') ?: [];
}

function doc_template(string $code): ?array
{
    return DB::one('SELECT * FROM templates WHERE code = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$code]);
}

/** Replace {{field}} placeholders in a template with data values. */
function merge_template(string $html, array $data): string
{
    return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($data) {
        // Leave the verification-code placeholder intact; doc_store_generated()
        // substitutes the freshly generated code after merging.
        if ($m[1] === 'verification_code') {
            return $m[0];
        }
        $keys = explode('.', $m[1]);
        $v = $data;
        foreach ($keys as $k) {
            $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
        }
        return ($v === null || $v === '') ? '' : (string) $v;
    }, $html);
}

function doc_verification_code(): string
{
    return strtoupper(bin2hex(random_bytes(5)));
}

function tender_context(int $tenderId): array
{
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
    if (!$t) {
        return [];
    }
    return [
        'tender' => $t,
        'fy' => DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) $t['fy_id']]),
        'scheme' => $t['scheme_id'] ? DB::one('SELECT * FROM schemes WHERE id = ?', [(int) $t['scheme_id']]) : null,
        'fund' => $t['fund_id'] ? DB::one('SELECT * FROM funds WHERE id = ?', [(int) $t['fund_id']]) : null,
        'project' => $t['project_id'] ? DB::one('SELECT * FROM projects WHERE id = ?', [(int) $t['project_id']]) : null,
        'boq' => DB::all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [$tenderId]),
    ];
}

function doc_nit_merge_data(int $tenderId): array
{
    $ctx = tender_context($tenderId);
    if (!$ctx) {
        return [];
    }
    $t = $ctx['tender'];
    $fy = $ctx['fy'];
    $p = panchayat_context();
    return [
        'panchayat_name' => $p['gram_panchayat'] ?? APP_NAME,
        'panchayat_address' => $p['office_address'] ?? '',
        'panchayat_phone' => $p['phone'] ?? '',
        'panchayat_email' => $p['email'] ?? '',
        'nit_number' => $t['nit_number'] ?: $t['tender_number'],
        'nit_date' => fmt_date($t['publication_date'] ?: $t['created_at']),
        'tender_number' => $t['tender_number'],
        'fy_label' => $fy ? $fy['label'] : '',
        'work_name' => $t['work_name'] ?: $t['title'],
        'description' => $t['description'] ?? '',
        'location' => $t['location'] ?? '',
        'estimated_cost' => inr($t['estimated_cost_minor']),
        'emd' => $t['emd_minor'] !== null ? inr($t['emd_minor']) : '—',
        'tender_fee' => $t['tender_fee_minor'] !== null ? inr($t['tender_fee_minor']) : '—',
        'completion_period' => $t['completion_period_days'] ? $t['completion_period_days'] . ' days' : 'As per tender document',
        'bid_start_date' => fmt_date($t['bid_start_date']),
        'bid_close_date' => fmt_date($t['bid_close_date']),
        'technical_open_date' => fmt_date($t['technical_open_date']),
        'financial_open_date' => fmt_date($t['financial_open_date']),
        'eligibility_notes' => $t['eligibility_notes'] ?: 'As per tender document.',
        'special_conditions' => $t['special_conditions'] ?: ($t['general_conditions'] ?: 'As per tender document.'),
        'authority_name' => $p['pradhan'] ?: ($p['panchayat_secretary'] ?? ''),
        'authority_designation' => $p['pradhan'] ? 'Pradhan' : 'Panchayat Secretary',
        'scheme' => $ctx['scheme'] ? $ctx['scheme']['name'] : '',
        'generated_at' => fmt_dt(date('c')),
        'verification_code' => '',
        'system_name' => APP_NAME,
    ];
}

/** Write a generated draft to disk + metadata tables; returns the document row. */
function doc_store_generated(string $docType, string $entityType, ?int $entityId, string $title, string $html, array $actor): array
{
    if (!is_dir(GENERATED_DIR)) {
        @mkdir(GENERATED_DIR, 0775, true);
    }
    $code = doc_verification_code();
    $html = str_replace('{{verification_code}}', $code, $html);
    $stored = uid() . '.html';
    file_put_contents(GENERATED_DIR . '/' . $stored, $html);
    $docId = DB::insert(
        'INSERT INTO documents (uid, entity_type, entity_id, category, original_name, stored_name, mime_type, size_bytes, sha256, uploaded_by, version)
         VALUES (?,?,?,?,?,?,?,?,?,?,1)',
        [uid(), $entityType, $entityId, $docType, $title . '.html', $stored, 'text/html', strlen($html), hash('sha256', $html), (int) $actor['id']]
    );
    DB::insert(
        'INSERT INTO document_generations (uid, doc_type, entity_type, entity_id, document_id, verification_code, generated_by)
         VALUES (?,?,?,?,?,?,?)',
        [uid(), $docType, $entityType, $entityId, $docId, $code, (int) $actor['id']]
    );
    return DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
}

function document_generate_nit(int $tenderId, array $actor): array
{
    $tpl = doc_template('nit');
    $data = doc_nit_merge_data($tenderId);
    $html = $tpl ? merge_template($tpl['content_html'], $data) : doc_default_nit_html($data);
    return doc_store_generated('nit', 'tender', $tenderId, 'Notice Inviting Tender', $html, $actor);
}

function document_generate_loa(int $awardId, string $loaNumber, array $actor): array
{
    $a = DB::one('SELECT * FROM awards WHERE id = ?', [$awardId]);
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $c = DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $a['contractor_id']]);
    $p = panchayat_context();
    $data = [
        'panchayat_name' => $p['gram_panchayat'] ?? APP_NAME,
        'panchayat_address' => $p['office_address'] ?? '',
        'loa_number' => $loaNumber,
        'loa_date' => fmt_date(today_iso()),
        'contractor_name' => $c ? $c['legal_name'] : '',
        'contractor_address' => $c ? ($c['address'] ?? '') : '',
        'work_name' => $t['work_name'] ?: $t['title'],
        'tender_number' => $t['tender_number'],
        'awarded_amount' => inr($a['awarded_amount_minor']),
        'completion_period' => $t['completion_period_days'] ? $t['completion_period_days'] . ' days' : 'As per tender',
        'security_deposit' => $a['security_deposit_minor'] !== null ? inr($a['security_deposit_minor']) : 'As per tender',
        'authority_name' => $p['pradhan'] ?: ($p['panchayat_secretary'] ?? ''),
        'authority_designation' => $p['pradhan'] ? 'Pradhan' : 'Panchayat Secretary',
        'generated_at' => fmt_dt(date('c')),
        'verification_code' => '',
        'system_name' => APP_NAME,
    ];
    $tpl = doc_template('loa');
    $html = $tpl ? merge_template($tpl['content_html'], $data) : doc_default_loa_html($data);
    return doc_store_generated('loa', 'award', $awardId, 'Letter of Acceptance', $html, $actor);
}

function document_generate_work_order(int $awardId, array $actor): array
{
    $a = DB::one('SELECT * FROM awards WHERE id = ?', [$awardId]);
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $c = DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $a['contractor_id']]);
    $wo = DB::one('SELECT * FROM work_orders WHERE id = ?', [(int) $a['work_order_id']]);
    $ag = $a['agreement_id'] ? DB::one('SELECT * FROM agreements WHERE id = ?', [(int) $a['agreement_id']]) : null;
    $p = panchayat_context();
    $data = [
        'panchayat_name' => $p['gram_panchayat'] ?? APP_NAME,
        'panchayat_address' => $p['office_address'] ?? '',
        'work_order_number' => $wo ? $wo['work_order_number'] : '',
        'work_order_date' => $wo ? fmt_date($wo['start_date']) : '',
        'tender_number' => $t['tender_number'],
        'agreement_number' => $ag ? $ag['agreement_number'] : '—',
        'contractor_name' => $c ? $c['legal_name'] : '',
        'contractor_address' => $c ? ($c['address'] ?? '') : '',
        'work_name' => $t['work_name'] ?: $t['title'],
        'location' => $t['location'] ?? '',
        'amount' => $wo ? inr($wo['amount_minor']) : inr($a['awarded_amount_minor']),
        'start_date' => $wo ? fmt_date($wo['start_date']) : '—',
        'completion_date' => $wo ? fmt_date($wo['completion_date']) : '—',
        'conditions' => $wo['conditions'] ?? ($t['special_conditions'] ?: 'As per agreement.'),
        'authority_name' => $p['pradhan'] ?: ($p['panchayat_secretary'] ?? ''),
        'authority_designation' => $p['pradhan'] ? 'Pradhan' : 'Panchayat Secretary',
        'generated_at' => fmt_dt(date('c')),
        'verification_code' => '',
        'system_name' => APP_NAME,
    ];
    $tpl = doc_template('work_order');
    $html = $tpl ? merge_template($tpl['content_html'], $data) : doc_default_work_order_html($data);
    return doc_store_generated('work_order', 'work_order', $wo ? (int) $wo['id'] : $awardId, 'Work Order', $html, $actor);
}

function document_generate_completion(int $projectId, array $actor): array
{
    $comp = completion_get_or_create($projectId);
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    $t = $comp['tender_id'] ? DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $comp['tender_id']]) : null;
    $c = $p['contractor_id'] ? DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $p['contractor_id']]) : null;
    $pan = panchayat_context();
    $data = [
        'panchayat_name' => $pan['gram_panchayat'] ?? APP_NAME,
        'panchayat_address' => $pan['office_address'] ?? '',
        'certificate_no' => 'COMP-' . $projectId,
        'completion_date' => fmt_date($comp['completion_date'] ?: $comp['handover_date']),
        'work_name' => $p['work_name'],
        'tender_number' => $t ? $t['tender_number'] : '—',
        'contractor_name' => $c ? $c['legal_name'] : 'the contractor',
        'final_bill_number' => $comp['final_bill_id'] ? (string) DB::val('SELECT bill_number FROM bills WHERE id = ?', [(int) $comp['final_bill_id']]) : '—',
        'authority_name' => $pan['pradhan'] ?: ($pan['panchayat_secretary'] ?? ''),
        'authority_designation' => $pan['pradhan'] ? 'Pradhan' : 'Panchayat Secretary',
        'generated_at' => fmt_dt(date('c')),
        'verification_code' => '',
        'system_name' => APP_NAME,
    ];
    $tpl = doc_template('completion_certificate');
    $html = $tpl ? merge_template($tpl['content_html'], $data) : doc_default_completion_html($data);
    $doc = doc_store_generated('completion_certificate', 'project', $projectId, 'Completion Certificate', $html, $actor);
    DB::run('UPDATE completions SET certificate_document_id = ? WHERE id = ?', [(int) $doc['id'], (int) $comp['id']]);
    return $doc;
}

// ---------------------------------------------------------------------------
// Fallback templates (used only if the `templates` table has no record)
// ---------------------------------------------------------------------------
function doc_footer_notice(): string
{
    return '<p class="doc-notice">Generated by ' . e(APP_NAME) . ' on {{generated_at}}. Verification code: <strong>{{verification_code}}</strong>. '
        . 'This is a system-generated draft based on configured templates and is not an official Government of West Bengal document '
        . 'unless duly signed and issued by the competent authority.</p>';
}

function doc_default_nit_html(array $d): string
{
    $row = function ($k, $v) {
        return '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>';
    };
    return '<div class="doc">
      <div class="doc-head">
        <strong>' . e($d['panchayat_name']) . '</strong><br>' . e($d['panchayat_address']) . '<br>
        Phone: ' . e($d['panchayat_phone']) . ' | Email: ' . e($d['panchayat_email']) . '
      </div>
      <h1>NOTICE INVITING TENDER (NIT)</h1>
      <p><strong>NIT No.:</strong> ' . e($d['nit_number']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . e($d['nit_date']) . '</p>
      <p><strong>Tender No.:</strong> ' . e($d['tender_number']) . ' &nbsp;&nbsp; <strong>Financial Year:</strong> ' . e($d['fy_label']) . '</p>
      <p>Sealed bids are invited for the following work:</p>
      <table class="doc-table">'
        . $row('Name of Work', $d['work_name'])
        . $row('Description', $d['description'])
        . $row('Location', $d['location'])
        . $row('Estimated Cost', $d['estimated_cost'])
        . $row('EMD', $d['emd'])
        . $row('Tender Fee', $d['tender_fee'])
        . $row('Completion Period', $d['completion_period'])
        . $row('Bid Start Date', $d['bid_start_date'])
        . $row('Bid Closing Date', $d['bid_close_date'])
        . $row('Technical Bid Opening', $d['technical_open_date'])
        . $row('Financial Bid Opening', $d['financial_open_date'])
        . '</table>
      <h3>Eligibility</h3><p>' . e($d['eligibility_notes']) . '</p>
      <h3>Conditions</h3><p>' . e($d['special_conditions']) . '</p>
      <div class="doc-sign">
        <strong>' . e($d['authority_name']) . '</strong><br>' . e($d['authority_designation']) . '<br>' . e($d['panchayat_name']) . '
      </div>' . doc_footer_notice() . '</div>';
}

function doc_default_loa_html(array $d): string
{
    return '<div class="doc">
      <div class="doc-head"><strong>' . e($d['panchayat_name']) . '</strong><br>' . e($d['panchayat_address']) . '</div>
      <h1>LETTER OF ACCEPTANCE (LOA)</h1>
      <p><strong>LOA No.:</strong> ' . e($d['loa_number']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . e($d['loa_date']) . '</p>
      <p>To,<br><strong>' . e($d['contractor_name']) . '</strong><br>' . e($d['contractor_address']) . '</p>
      <p>Your bid for the work <strong>' . e($d['work_name']) . '</strong> (Tender No. ' . e($d['tender_number']) . ') has been accepted at a total value of <strong>' . e($d['awarded_amount']) . '</strong>.</p>
      <p>The completion period is <strong>' . e($d['completion_period']) . '</strong> from the date of issue of the Work Order. Please submit the required security deposit of <strong>' . e($d['security_deposit']) . '</strong> and execute the agreement within the stipulated time.</p>
      <div class="doc-sign"><strong>' . e($d['authority_name']) . '</strong><br>' . e($d['authority_designation']) . '<br>' . e($d['panchayat_name']) . '</div>'
      . doc_footer_notice() . '</div>';
}

function doc_default_work_order_html(array $d): string
{
    return '<div class="doc">
      <div class="doc-head"><strong>' . e($d['panchayat_name']) . '</strong><br>' . e($d['panchayat_address']) . '</div>
      <h1>WORK ORDER</h1>
      <p><strong>Work Order No.:</strong> ' . e($d['work_order_number']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . e($d['work_order_date']) . '</p>
      <p><strong>Tender No.:</strong> ' . e($d['tender_number']) . ' &nbsp;|&nbsp; <strong>Agreement No.:</strong> ' . e($d['agreement_number']) . '</p>
      <p>To,<br><strong>' . e($d['contractor_name']) . '</strong><br>' . e($d['contractor_address']) . '</p>
      <p>You are hereby directed to commence the work <strong>' . e($d['work_name']) . '</strong> at <strong>' . e($d['location']) . '</strong> for a value of <strong>' . e($d['amount']) . '</strong>.</p>
      <p><strong>Start Date:</strong> ' . e($d['start_date']) . ' &nbsp;&nbsp; <strong>Completion Date:</strong> ' . e($d['completion_date']) . '</p>
      <p>' . e($d['conditions']) . '</p>
      <div class="doc-sign"><strong>' . e($d['authority_name']) . '</strong><br>' . e($d['authority_designation']) . '<br>' . e($d['panchayat_name']) . '</div>'
      . doc_footer_notice() . '</div>';
}

function doc_default_completion_html(array $d): string
{
    return '<div class="doc">
      <div class="doc-head"><strong>' . e($d['panchayat_name']) . '</strong><br>' . e($d['panchayat_address']) . '</div>
      <h1>COMPLETION CERTIFICATE</h1>
      <p><strong>Certificate No.:</strong> ' . e($d['certificate_no']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . e($d['completion_date']) . '</p>
      <p>This is to certify that the work <strong>' . e($d['work_name']) . '</strong> under Tender No. ' . e($d['tender_number']) . ' awarded to <strong>' . e($d['contractor_name']) . '</strong> has been completed and inspected, and the final measurement and final bill have been processed.</p>
      <p><strong>Completion Date:</strong> ' . e($d['completion_date']) . ' &nbsp;&nbsp; <strong>Final Bill No.:</strong> ' . e($d['final_bill_number']) . '</p>
      <div class="doc-sign"><strong>' . e($d['authority_name']) . '</strong><br>' . e($d['authority_designation']) . '<br>' . e($d['panchayat_name']) . '</div>'
      . doc_footer_notice() . '</div>';
}
