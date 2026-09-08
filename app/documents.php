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

function panchayat_context(?int $panchayatId = null): array
{
    if ($panchayatId !== null && $panchayatId > 0) {
        return DB::one('SELECT * FROM panchayats WHERE id = ?', [$panchayatId]) ?: [];
    }
    $scope = current_scope_panchayat_id();
    if ($scope !== null) {
        return DB::one('SELECT * FROM panchayats WHERE id = ?', [$scope]) ?: [];
    }
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
        return ($v === null || $v === '') ? '' : e((string) $v);
    }, $html);
}

function doc_verification_code(): string
{
    return strtoupper(bin2hex(random_bytes(5)));
}

function document_entity_panchayat(string $entityType, ?int $entityId): ?int
{
    if (!$entityId) {
        return current_scope_panchayat_id() ?? actor_panchayat_id();
    }
    $map = [
        'tender' => 'tenders',
        'project' => 'projects',
        'contractor' => 'contractors',
        'bill' => 'bills',
        'payment' => 'payments',
        'award' => 'awards',
        'work_order' => 'work_orders',
        'agreement' => 'agreements',
        'measurement' => 'measurements',
        'completion' => 'completions',
    ];
    if (!isset($map[$entityType])) {
        throw validation('Unsupported document entity type.');
    }
    $row = DB::one('SELECT panchayat_id FROM ' . sql_ident($map[$entityType]) . ' WHERE id = ?', [$entityId]);
    if (!$row) {
        throw not_found('Document entity not found');
    }
    scope_assert_row($row, null, $entityType);
    return $row['panchayat_id'] !== null ? (int) $row['panchayat_id'] : null;
}

function doc_plain_text_from_html(string $html): string
{
    $html = preg_replace('/<\s*(br|\/p|\/div|\/h[1-6]|\/tr|li)\s*\/?>/i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function doc_pdf_safe_text(string $text): string
{
    $text = str_replace(['₹', '–', '—', '“', '”', '‘', '’', '•'], ['Rs.', '-', '-', '"', '"', "'", "'", '-'], $text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? $text;
}

function doc_pdf_escape(string $text): string
{
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
}

function doc_pdf_lines(string $text, int $width = 92): array
{
    $out = [];
    foreach (preg_split('/\R/', doc_pdf_safe_text($text)) as $para) {
        $para = trim($para);
        if ($para === '') {
            $out[] = '';
            continue;
        }
        foreach (explode("\n", wordwrap($para, $width, "\n", true)) as $line) {
            $out[] = $line;
        }
    }
    return $out ?: [''];
}

function doc_create_simple_pdf(string $text, string $title, string $path): void
{
    $lines = doc_pdf_lines($title . "\n\n" . $text, 92);
    $chunks = array_chunk($lines, 52);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $kids = [];
    foreach ($chunks as $i => $chunk) {
        $pageObj = 4 + ($i * 2);
        $contentObj = $pageObj + 1;
        $kids[] = $pageObj . ' 0 R';
        $stream = "BT\n/F1 10 Tf\n50 800 Td\n14 TL\n";
        foreach ($chunk as $line) {
            $stream .= '(' . doc_pdf_escape($line) . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
        $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects, SORT_NUMERIC);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $max = max(array_keys($objects));
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($max + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    file_put_contents($path, $pdf);
}

function tender_context(int $tenderId): array
{
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
    if (!$t) {
        return [];
    }
    scope_assert_row($t, null, 'tender');
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
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
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

/** Write a generated PDF to disk + metadata tables; returns the PDF document row. */
function doc_store_generated(string $docType, string $entityType, ?int $entityId, string $title, string $html, array $actor): array
{
    if (!is_dir(GENERATED_DIR)) {
        @mkdir(GENERATED_DIR, 0775, true);
    }
    $panchayatId = document_entity_panchayat($entityType, $entityId) ?? actor_panchayat_id($actor);
    $code = doc_verification_code();
    $html = str_replace('{{verification_code}}', $code, $html);
    $html = str_replace('{{generated_at}}', e(fmt_dt(date('c'))), $html);
    $base = uid();
    $htmlStored = $base . '.html';
    $pdfStored = $base . '.pdf';
    $htmlPath = GENERATED_DIR . '/' . $htmlStored;
    $pdfPath = GENERATED_DIR . '/' . $pdfStored;
    file_put_contents($htmlPath, $html);
    doc_create_simple_pdf(doc_plain_text_from_html($html), $title, $pdfPath);
    $docId = DB::insert(
        'INSERT INTO documents (uid, panchayat_id, entity_type, entity_id, category, original_name, stored_name, mime_type, size_bytes, sha256, uploaded_by, version)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1)',
        [uid(), $panchayatId, $entityType, $entityId, $docType, $title . '.pdf', $pdfStored, 'application/pdf', filesize($pdfPath), hash_file('sha256', $pdfPath), (int) $actor['id']]
    );
    DB::insert(
        'INSERT INTO document_generations (uid, panchayat_id, doc_type, entity_type, entity_id, document_id, verification_code, generated_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), $panchayatId, $docType, $entityType, $entityId, $docId, $code, (int) $actor['id']]
    );
    audit_record('document.generate', 'document', $docId, $title, ['newValue' => ['docType' => $docType, 'entityType' => $entityType, 'entityId' => $entityId, 'verificationCode' => $code]]);
    return DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
}

function document_safe_original_name(string $name): string
{
    $name = basename(str_replace("\\", "/", $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'document';
    $name = trim($name, ' ._');
    return $name !== '' ? substr($name, 0, 180) : 'document';
}

function document_allowed_extensions(): array
{
    return [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];
}


function document_mime_matches_extension(string $ext, ?string $detected, string $expected): bool
{
    if (!$detected) {
        return true;
    }
    $detected = strtolower(trim(explode(';', $detected)[0]));
    $expected = strtolower($expected);
    $generic = ['application/octet-stream', 'binary/octet-stream'];
    if (in_array($detected, $generic, true)) {
        return true;
    }
    $allowedByExt = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'docx' => [$expected, 'application/zip'],
        'xlsx' => [$expected, 'application/zip'],
        'odt' => [$expected, 'application/zip'],
        'ods' => [$expected, 'application/zip'],
    ];
    return in_array($detected, $allowedByExt[$ext] ?? [$expected], true);
}

function document_upload(array $file, array $data, array $actor): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw validation('Upload failed or no file was selected.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
        throw validation('File size is invalid or exceeds the configured upload limit.');
    }
    $original = document_safe_original_name((string) ($file['name'] ?? 'document'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = document_allowed_extensions();
    if (!isset($allowed[$ext])) {
        throw validation('File type is not allowed. Allowed: ' . implode(', ', array_keys($allowed)));
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw validation('Uploaded file is not readable.');
    }
    $detected = null;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $detected = finfo_file($fi, $tmp) ?: null; finfo_close($fi); }
    }
    $detectedNorm = $detected ? strtolower(trim(explode(';', $detected)[0])) : null;
    $dangerous = ['text/html', 'image/svg+xml', 'application/x-php', 'application/x-httpd-php', 'application/javascript', 'text/javascript'];
    if ($detectedNorm && in_array($detectedNorm, $dangerous, true)) {
        throw validation('Potentially unsafe file content was rejected.');
    }
    if (!document_mime_matches_extension($ext, $detectedNorm, $allowed[$ext])) {
        throw validation('File content type does not match the file extension.');
    }
    $entityType = preg_replace('/[^a-z_]/', '', strtolower((string) ($data['entity_type'] ?? '')));
    $entityId = (int) ($data['entity_id'] ?? 0);
    if ($entityType === '' || $entityId <= 0) {
        throw validation('Document entity type and id are required.');
    }
    $panchayatId = document_entity_panchayat($entityType, $entityId);
    $category = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($data['category'] ?? 'supporting')) ?: 'supporting';
    $category = substr($category, 0, 60);
    $subdir = date('Y') . '/' . date('m');
    $dir = UPLOADS_DIR . '/' . $subdir;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $stored = $subdir . '/' . uid() . '.' . $ext;
    $dest = UPLOADS_DIR . '/' . $stored;
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$moved) {
        throw validation('Unable to store uploaded file.');
    }
    @chmod($dest, 0644);
    $version = (int) DB::val('SELECT COALESCE(MAX(version),0) FROM documents WHERE entity_type = ? AND entity_id = ? AND category = ?', [$entityType, $entityId, $category]) + 1;
    $docId = DB::insert(
        'INSERT INTO documents (uid, panchayat_id, entity_type, entity_id, category, original_name, stored_name, mime_type, size_bytes, sha256, uploaded_by, version)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [uid(), $panchayatId, $entityType, $entityId, $category, $original, $stored, $detected ?: $allowed[$ext], filesize($dest), hash_file('sha256', $dest), (int) $actor['id'], $version]
    );
    audit_record('document.upload', 'document', $docId, $original, ['newValue' => ['entityType' => $entityType, 'entityId' => $entityId, 'category' => $category, 'sha256' => hash_file('sha256', $dest)]]);
    return DB::one('SELECT * FROM documents WHERE id = ?', [$docId]);
}

function document_file_path(array $doc): ?string
{
    $stored = str_replace("\\", "/", (string) ($doc['stored_name'] ?? ''));
    if ($stored === '' || strpos($stored, '..') !== false || str_starts_with($stored, '/')) {
        return null;
    }
    foreach ([GENERATED_DIR, UPLOADS_DIR] as $base) {
        $path = $base . '/' . $stored;
        if (is_file($path)) {
            $realBase = realpath($base);
            $realPath = realpath($path);
            if ($realBase && $realPath && ($realPath === $realBase || str_starts_with($realPath, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
                return $realPath;
            }
        }
    }
    return null;
}

function document_generate_nit(int $tenderId, array $actor): array
{
    $tpl = doc_template('nit');
    $data = doc_nit_merge_data($tenderId);
    if (!$data) { throw not_found('Tender not found'); }
    $html = $tpl ? merge_template($tpl['content_html'], $data) : doc_default_nit_html($data);
    return doc_store_generated('nit', 'tender', $tenderId, 'Notice Inviting Tender', $html, $actor);
}

function document_generate_loa(int $awardId, string $loaNumber, array $actor): array
{
    $a = DB::one('SELECT * FROM awards WHERE id = ?', [$awardId]);
    if (!$a) { throw not_found('Award not found'); }
    scope_assert_row($a, $actor, 'award');
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $c = DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $a['contractor_id']]);
    $p = panchayat_context($a['panchayat_id'] !== null ? (int) $a['panchayat_id'] : null);
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

function document_generate_agreement(int $agreementId, array $actor): array
{
    $ag = DB::one('SELECT * FROM agreements WHERE id = ?', [$agreementId]);
    if (!$ag) { throw not_found('Agreement not found'); }
    scope_assert_row($ag, $actor, 'agreement');
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $ag['tender_id']]);
    $c = DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $ag['contractor_id']]);
    $p = panchayat_context($ag['panchayat_id'] !== null ? (int) $ag['panchayat_id'] : null);
    $data = [
        'panchayat_name' => $p['gram_panchayat'] ?? APP_NAME,
        'panchayat_address' => $p['office_address'] ?? '',
        'agreement_number' => $ag['agreement_number'],
        'agreement_date' => fmt_date($ag['execution_date']),
        'contractor_name' => $c ? $c['legal_name'] : '',
        'contractor_address' => $c ? ($c['address'] ?? '') : '',
        'work_name' => $t ? ($t['work_name'] ?: $t['title']) : '',
        'tender_number' => $t ? $t['tender_number'] : '',
        'amount' => inr($ag['amount_minor']),
        'completion_period' => $ag['completion_period_days'] ? $ag['completion_period_days'] . ' days' : 'As per tender',
        'security_deposit' => inr($ag['security_deposit_minor']),
        'conditions' => $ag['conditions'] ?: 'As per tender document, LOA, and competent authority instructions.',
        'authority_name' => $p['pradhan'] ?: ($p['panchayat_secretary'] ?? ''),
        'authority_designation' => $p['pradhan'] ? 'Pradhan' : 'Panchayat Secretary',
        'generated_at' => fmt_dt(date('c')),
        'verification_code' => '',
        'system_name' => APP_NAME,
    ];
    $tpl = doc_template('agreement');
    $html = $tpl ? merge_template($tpl['content_html'], $data) : doc_default_agreement_html($data);
    return doc_store_generated('agreement', 'agreement', $agreementId, 'Agreement', $html, $actor);
}

function document_generate_work_order(int $awardId, array $actor): array
{
    $a = DB::one('SELECT * FROM awards WHERE id = ?', [$awardId]);
    if (!$a) { throw not_found('Award not found'); }
    scope_assert_row($a, $actor, 'award');
    if (empty($a['work_order_id'])) { throw conflict('Work order has not been issued yet'); }
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $c = DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $a['contractor_id']]);
    $wo = DB::one('SELECT * FROM work_orders WHERE id = ?', [(int) $a['work_order_id']]);
    if (!$wo) { throw not_found('Work order not found'); }
    $ag = $a['agreement_id'] ? DB::one('SELECT * FROM agreements WHERE id = ?', [(int) $a['agreement_id']]) : null;
    $p = panchayat_context($a['panchayat_id'] !== null ? (int) $a['panchayat_id'] : null);
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
    $doc = doc_store_generated('work_order', 'work_order', (int) $wo['id'], 'Work Order', $html, $actor);
    DB::run('UPDATE work_orders SET document_id = ? WHERE id = ?', [(int) $doc['id'], (int) $wo['id']]);
    return $doc;
}

function document_generate_completion(int $projectId, array $actor): array
{
    $comp = completion_get_or_create($projectId);
    $p = project_get($projectId);
    scope_assert_row($comp, $actor, 'completion');
    $t = $comp['tender_id'] ? DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $comp['tender_id']]) : null;
    $c = $p['contractor_id'] ? DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $p['contractor_id']]) : null;
    $pan = panchayat_context($p['panchayat_id'] !== null ? (int) $p['panchayat_id'] : null);
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
// Additional printable PDF generators (all sourced from live database records)
// ---------------------------------------------------------------------------
function doc_html_table(array $headers, array $rows): string
{
    $out = '<table width="100%" border="1" cellpadding="5" style="border-collapse:collapse;font-size:10pt"><thead><tr>';
    foreach ($headers as $h) {
        $out .= '<th>' . e($h) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    if (!$rows) {
        $out .= '<tr><td colspan="' . max(1, count($headers)) . '">No records</td></tr>';
    }
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($row as $cell) {
            $out .= '<td>' . e((string) ($cell ?? '')) . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

function document_generate_tender_document(int $tenderId, array $actor): array
{
    $ctx = tender_context($tenderId);
    if (!$ctx) { throw not_found('Tender not found'); }
    $t = $ctx['tender'];
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
    $fy = $ctx['fy'];
    $totals = ['baseMinor' => 0, 'taxMinor' => 0, 'totalMinor' => 0];
    if (function_exists('fin_boq_totals')) {
        $totals = fin_boq_totals($tenderId);
    } else {
        foreach ($ctx['boq'] as $it) {
            $amt = qty_rate($it['quantity'], $it['estimated_rate_minor']);
            $totals['baseMinor'] += $amt;
            $totals['totalMinor'] += $amt;
        }
    }
    $boqRows = [];
    foreach ($ctx['boq'] as $it) {
        $boqRows[] = [
            $it['item_no'],
            $it['description'],
            $it['unit'],
            h_qty_for_doc($it['quantity']),
            inr($it['estimated_rate_minor']),
            inr(qty_rate($it['quantity'], $it['estimated_rate_minor'])),
        ];
    }
    $html = '<div style="font-family:Arial,sans-serif;font-size:11pt;line-height:1.45">'
        . '<p style="text-align:center"><strong>' . e($p['gram_panchayat'] ?? APP_NAME) . '</strong><br>' . e($p['office_address'] ?? '') . '</p><hr>'
        . '<h2 style="text-align:center">TENDER DOCUMENT</h2>'
        . '<p><strong>Tender No.:</strong> ' . e($t['tender_number']) . ' &nbsp; <strong>NIT No.:</strong> ' . e($t['nit_number'] ?: $t['tender_number']) . ' &nbsp; <strong>FY:</strong> ' . e($fy['label'] ?? '') . '</p>'
        . doc_html_table(['Field', 'Value'], [
            ['Name of Work', $t['work_name'] ?: $t['title']],
            ['Location', $t['location']],
            ['Procurement', $t['procurement_category'] . ' / ' . $t['tender_type']],
            ['Estimated Cost', inr($t['estimated_cost_minor'])],
            ['Tender Value', inr($t['tender_value_minor'])],
            ['EMD', $t['emd_minor'] !== null ? inr($t['emd_minor']) : 'As configured / not applicable'],
            ['Tender Fee', $t['tender_fee_minor'] !== null ? inr($t['tender_fee_minor']) : 'As configured / not applicable'],
            ['Completion Period', $t['completion_period_days'] ? $t['completion_period_days'] . ' days' : 'As per approved conditions'],
            ['BOQ Total', inr($totals['totalMinor'] ?? 0)],
        ])
        . '<h3>Schedule</h3>'
        . doc_html_table(['Milestone', 'Date'], [
            ['Publication', fmt_date($t['publication_date'])],
            ['Bid Start', fmt_date($t['bid_start_date'])],
            ['Bid Closing', fmt_date($t['bid_close_date'])],
            ['Technical Opening', fmt_date($t['technical_open_date'])],
            ['Financial Opening', fmt_date($t['financial_open_date'])],
        ])
        . '<h3>Eligibility / Conditions</h3>'
        . '<p><strong>Eligibility:</strong> ' . e($t['eligibility_notes'] ?: 'As per configured/approved tender conditions. Verification by competent authority required.') . '</p>'
        . '<p><strong>General conditions:</strong> ' . e($t['general_conditions'] ?: 'As per approved Panchayat template/conditions.') . '</p>'
        . '<p><strong>Special conditions:</strong> ' . e($t['special_conditions'] ?: 'As per approved Panchayat template/conditions.') . '</p>'
        . '<h3>BOQ Summary</h3>' . doc_html_table(['Item', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], $boqRows)
        . '<p style="font-size:8pt;color:#555;margin-top:24pt">System-generated draft from database record. Verify all legal/procurement clauses before issue. Verification code: {{verification_code}}.</p>'
        . '</div>';
    return doc_store_generated('tender_document', 'tender', $tenderId, 'Tender Document', $html, $actor);
}

function h_qty_for_doc($q): string
{
    $f = (float) $q;
    if ($f === floor($f)) { return number_format($f, 0, '.', ''); }
    return rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

function document_generate_boq(int $tenderId, array $actor): array
{
    $ctx = tender_context($tenderId);
    if (!$ctx) { throw not_found('Tender not found'); }
    $t = $ctx['tender'];
    $rows = [];
    $total = 0;
    foreach ($ctx['boq'] as $it) {
        $amt = qty_rate($it['quantity'], $it['estimated_rate_minor']);
        $total += $amt;
        $rows[] = [$it['item_no'], $it['group_name'], $it['description'], $it['specification'], $it['unit'], h_qty_for_doc($it['quantity']), inr($it['estimated_rate_minor']), inr($amt)];
    }
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
    $html = '<div style="font-family:Arial,sans-serif;font-size:10.5pt;line-height:1.35">'
        . '<p style="text-align:center"><strong>' . e($p['gram_panchayat'] ?? APP_NAME) . '</strong><br>' . e($p['office_address'] ?? '') . '</p><hr>'
        . '<h2 style="text-align:center">BILL OF QUANTITIES</h2>'
        . '<p><strong>Tender:</strong> ' . e($t['tender_number']) . '<br><strong>Work:</strong> ' . e($t['work_name'] ?: $t['title']) . '</p>'
        . doc_html_table(['Item', 'Group', 'Description', 'Specification', 'Unit', 'Qty', 'Estimated Rate', 'Amount'], $rows)
        . '<p><strong>BOQ Total:</strong> ' . e(inr($total)) . '</p>'
        . '<p style="font-size:8pt;color:#555">System-generated BOQ from locked source records. Verification code: {{verification_code}}.</p>'
        . '</div>';
    return doc_store_generated('boq', 'tender', $tenderId, 'BOQ', $html, $actor);
}

function document_generate_technical_evaluation(int $tenderId, array $actor): array
{
    $ctx = tender_context($tenderId);
    if (!$ctx) { throw not_found('Tender not found'); }
    $t = $ctx['tender'];
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
    $rows = DB::all(
        'SELECT c.criterion, c.requirement, tb.bidder_label, co.legal_name, te.result, te.remarks, te.evaluated_at, u.name evaluator
           FROM technical_evaluations te
           LEFT JOIN evaluation_criteria c ON c.id = te.criterion_id
           LEFT JOIN tender_bidders tb ON tb.id = te.bidder_id
           LEFT JOIN contractors co ON co.id = tb.contractor_id
           LEFT JOIN users u ON u.id = te.evaluated_by
          WHERE te.tender_id = ? ORDER BY tb.id, c.sort_order, c.id',
        [$tenderId]
    );
    $outRows = [];
    foreach ($rows as $r) {
        $outRows[] = [
            $r['legal_name'] ?: $r['bidder_label'],
            $r['criterion'],
            $r['requirement'],
            strtoupper(str_replace('_', ' ', (string) $r['result'])),
            $r['remarks'],
            $r['evaluator'],
            fmt_dt($r['evaluated_at']),
        ];
    }
    $summary = DB::all('SELECT tb.bid_status, COUNT(*) c FROM tender_bidders tb WHERE tb.tender_id = ? GROUP BY tb.bid_status', [$tenderId]);
    $sumRows = array_map(fn($r) => [strtoupper(str_replace('_', ' ', $r['bid_status'])), (string) $r['c']], $summary);
    $html = '<div style="font-family:Arial,sans-serif;font-size:10.5pt;line-height:1.35">'
        . '<p style="text-align:center"><strong>' . e($p['gram_panchayat'] ?? APP_NAME) . '</strong><br>' . e($p['office_address'] ?? '') . '</p><hr>'
        . '<h2 style="text-align:center">TECHNICAL EVALUATION STATEMENT</h2>'
        . '<p><strong>Tender:</strong> ' . e($t['tender_number']) . '<br><strong>Work:</strong> ' . e($t['work_name'] ?: $t['title']) . '</p>'
        . '<h3>Summary</h3>' . doc_html_table(['Bid Status', 'Count'], $sumRows)
        . '<h3>Evaluation Details</h3>' . doc_html_table(['Bidder', 'Criterion', 'Requirement', 'Result', 'Remarks', 'Evaluator', 'Date'], $outRows)
        . '<p style="font-size:8pt;color:#555">System-generated statement from database evaluation records. Verification code: {{verification_code}}.</p>'
        . '</div>';
    return doc_store_generated('technical_evaluation', 'tender', $tenderId, 'Technical Evaluation Statement', $html, $actor);
}

function document_generate_comparative(int $tenderId, array $actor): array
{
    $ctx = tender_context($tenderId);
    if (!$ctx) { throw not_found('Tender not found'); }
    $t = $ctx['tender'];
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
    $rows = [];
    $bids = DB::all(
        'SELECT tb.id bidder_id, tb.bid_status, tb.rank, tb.rejection_reason, c.legal_name, c.contractor_code, fb.total_amount_minor
           FROM tender_bidders tb
           JOIN contractors c ON c.id = tb.contractor_id
           LEFT JOIN financial_bids fb ON fb.bidder_id = tb.id AND fb.tender_id = tb.tender_id
          WHERE tb.tender_id = ? ORDER BY CASE WHEN tb.rank IS NULL THEN 999999 ELSE tb.rank END, tb.id',
        [$tenderId]
    );
    foreach ($bids as $b) {
        $total = $b['total_amount_minor'] !== null ? (int) $b['total_amount_minor'] : null;
        $variation = ($total !== null && (int) $t['estimated_cost_minor'] > 0) ? round(pct_diff($t['estimated_cost_minor'], $total), 2) . '%' : '—';
        $rows[] = [
            $b['rank'] !== null ? 'L' . (int) $b['rank'] : '—',
            $b['legal_name'],
            $b['contractor_code'],
            strtoupper(str_replace('_', ' ', $b['bid_status'])),
            $total !== null ? inr($total) : '—',
            $variation,
            $b['rejection_reason'] ?: '',
        ];
    }
    $html = '<div style="font-family:Arial,sans-serif;font-size:10.5pt;line-height:1.35">'
        . '<p style="text-align:center"><strong>' . e($p['gram_panchayat'] ?? APP_NAME) . '</strong><br>' . e($p['office_address'] ?? '') . '</p><hr>'
        . '<h2 style="text-align:center">COMPARATIVE STATEMENT</h2>'
        . '<p><strong>Tender:</strong> ' . e($t['tender_number']) . '<br><strong>Work:</strong> ' . e($t['work_name'] ?: $t['title']) . '<br><strong>Estimated Cost:</strong> ' . e(inr($t['estimated_cost_minor'])) . '</p>'
        . doc_html_table(['Rank', 'Bidder', 'Code', 'Qualification', 'Quoted Total', 'Variation', 'Remarks'], $rows)
        . '<p style="font-size:8pt;color:#555">Ranking is calculated from recorded financial bid totals according to the configured system method. Verification code: {{verification_code}}.</p>'
        . '</div>';
    return doc_store_generated('comparative_statement', 'tender', $tenderId, 'Comparative Statement', $html, $actor);
}

function document_generate_bill(int $billId, array $actor): array
{
    $b = DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
    if (!$b) { throw not_found('Bill not found'); }
    scope_assert_row($b, $actor, 'bill');
    $project = DB::one('SELECT * FROM projects WHERE id = ?', [(int) $b['project_id']]);
    $contractor = $b['contractor_id'] ? DB::one('SELECT * FROM contractors WHERE id = ?', [(int) $b['contractor_id']]) : null;
    $tender = $b['tender_id'] ? DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $b['tender_id']]) : null;
    $p = panchayat_context($b['panchayat_id'] !== null ? (int) $b['panchayat_id'] : null);
    $payments = DB::all("SELECT * FROM payments WHERE bill_id = ? AND status != 'cancelled' ORDER BY id", [$billId]);
    $payRows = [];
    $paid = 0;
    foreach ($payments as $pay) {
        $paid += (int) $pay['net_amount_minor'];
        $payRows[] = [$pay['voucher_no'], fmt_date($pay['payment_date']), inr($pay['net_amount_minor']), $pay['payment_method'], $pay['transaction_reference']];
    }
    $html = '<div style="font-family:Arial,sans-serif;font-size:11pt;line-height:1.4">'
        . '<p style="text-align:center"><strong>' . e($p['gram_panchayat'] ?? APP_NAME) . '</strong><br>' . e($p['office_address'] ?? '') . '</p><hr>'
        . '<h2 style="text-align:center">BILL / PAYMENT CERTIFICATION RECORD</h2>'
        . '<p><strong>Bill No.:</strong> ' . e($b['bill_number']) . ' &nbsp; <strong>Date:</strong> ' . e(fmt_date($b['bill_date'])) . ' &nbsp; <strong>Status:</strong> ' . e(strtoupper($b['status'])) . '</p>'
        . doc_html_table(['Field', 'Value'], [
            ['Work', $project['work_name'] ?? ''],
            ['Tender', $tender['tender_number'] ?? '—'],
            ['Contractor', $contractor['legal_name'] ?? '—'],
            ['Bill Type', strtoupper($b['bill_type'])],
            ['Gross Work Value', inr($b['gross_work_value_minor'])],
            ['Previous Certified', inr($b['previous_certified_minor'])],
            ['Current Bill', inr($b['current_bill_minor'])],
            ['Retention', inr($b['retention_minor'])],
            ['Tax', inr($b['tax_minor'])],
            ['Deductions/Recoveries', inr((int) $b['deductions_minor'] + (int) $b['recoveries_minor'])],
            ['Net Payable', inr($b['net_payable_minor'])],
            ['Payment Recorded', inr($paid)],
            ['Balance', inr(max(0, (int) $b['net_payable_minor'] - $paid))],
        ])
        . '<h3>Payments Recorded</h3>' . doc_html_table(['Voucher', 'Date', 'Net Amount', 'Method', 'Reference'], $payRows)
        . '<p style="font-size:8pt;color:#555">This document records bill/payment data in the portal. It does not execute bank payment. Verification code: {{verification_code}}.</p>'
        . '</div>';
    return doc_store_generated('bill', 'bill', $billId, 'Bill Record', $html, $actor);
}

function document_generate_complete_tender_file(int $tenderId, array $actor): array
{
    $ctx = tender_context($tenderId);
    if (!$ctx) { throw not_found('Tender not found'); }
    $t = $ctx['tender'];
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
    $docs = DB::all('SELECT category, original_name, version, sha256, created_at FROM documents WHERE entity_type = ? AND entity_id = ? ORDER BY id', ['tender', $tenderId]);
    $bidders = DB::all('SELECT tb.*, c.legal_name FROM tender_bidders tb JOIN contractors c ON c.id = tb.contractor_id WHERE tb.tender_id = ? ORDER BY tb.id', [$tenderId]);
    $award = DB::one('SELECT * FROM awards WHERE tender_id = ? ORDER BY id DESC LIMIT 1', [$tenderId]);
    $projectId = $t['project_id'] ? (int) $t['project_id'] : 0;
    $bills = $projectId ? DB::all('SELECT * FROM bills WHERE project_id = ? ORDER BY id', [$projectId]) : [];
    $payments = $projectId ? DB::all('SELECT * FROM payments WHERE project_id = ? ORDER BY id', [$projectId]) : [];
    $docRows = array_map(fn($d) => [$d['category'], $d['original_name'], 'v' . $d['version'], substr((string) $d['sha256'], 0, 16), fmt_dt($d['created_at'])], $docs);
    $bidRows = array_map(fn($b) => [$b['legal_name'], strtoupper(str_replace('_', ' ', $b['bid_status'])), $b['rank'] !== null ? 'L' . $b['rank'] : '—', $b['rejection_reason']], $bidders);
    $billRows = array_map(fn($b) => [$b['bill_number'], fmt_date($b['bill_date']), strtoupper($b['status']), inr($b['net_payable_minor'])], $bills);
    $payRows = array_map(fn($pay) => [$pay['voucher_no'], fmt_date($pay['payment_date']), strtoupper($pay['status']), inr($pay['net_amount_minor'])], $payments);
    $html = '<div style="font-family:Arial,sans-serif;font-size:10.5pt;line-height:1.35">'
        . '<p style="text-align:center"><strong>' . e($p['gram_panchayat'] ?? APP_NAME) . '</strong><br>' . e($p['office_address'] ?? '') . '</p><hr>'
        . '<h2 style="text-align:center">COMPLETE TENDER FILE INDEX</h2>'
        . '<p><strong>Tender:</strong> ' . e($t['tender_number']) . '<br><strong>Work:</strong> ' . e($t['work_name'] ?: $t['title']) . '<br><strong>Status:</strong> ' . e(strtoupper($t['status'])) . '</p>'
        . '<h3>Chronology</h3>' . doc_html_table(['Stage', 'Date/Status'], [
            ['Created', fmt_dt($t['created_at'])], ['Approved/Admin', $t['admin_approval_no'] . ' ' . fmt_date($t['admin_approval_date'])], ['Technical Sanction', $t['tech_sanction_no'] . ' ' . fmt_date($t['tech_sanction_date'])], ['Published', fmt_date($t['publication_date'])], ['Bid Closed', fmt_date($t['bid_close_date'])], ['Current Tender Status', strtoupper($t['status'])], ['Award', $award ? strtoupper($award['status']) . ' ' . inr($award['awarded_amount_minor']) : '—']
        ])
        . '<h3>Documents</h3>' . doc_html_table(['Category', 'File', 'Version', 'SHA256 Prefix', 'Created'], $docRows)
        . '<h3>Bidders / Evaluation</h3>' . doc_html_table(['Bidder', 'Status', 'Rank', 'Reason'], $bidRows)
        . '<h3>Bills</h3>' . doc_html_table(['Bill', 'Date', 'Status', 'Net'], $billRows)
        . '<h3>Payments</h3>' . doc_html_table(['Voucher', 'Date', 'Status', 'Net'], $payRows)
        . '<p style="font-size:8pt;color:#555">This package is an index generated from database records and registered document metadata. Confidential supporting files remain subject to portal permissions. Verification code: {{verification_code}}.</p>'
        . '</div>';
    return doc_store_generated('complete_tender_file', 'tender', $tenderId, 'Complete Tender File', $html, $actor);
}

// ---------------------------------------------------------------------------
// Fallback templates (used only if the `templates` table has no record)
// ---------------------------------------------------------------------------
function doc_footer_notice(): string
{
    return '<p class="doc-notice">Generated by ' . e(APP_NAME) . ' on ' . e(fmt_dt(date('c'))) . '. Verification code: <strong>{{verification_code}}</strong>. '
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

function doc_default_agreement_html(array $d): string
{
    return '<div class="doc">
      <div class="doc-head"><strong>' . e($d['panchayat_name']) . '</strong><br>' . e($d['panchayat_address']) . '</div>
      <h1>AGREEMENT</h1>
      <p><strong>Agreement No.:</strong> ' . e($d['agreement_number']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . e($d['agreement_date']) . '</p>
      <p>This agreement records the acceptance of terms for <strong>' . e($d['work_name']) . '</strong> (Tender No. ' . e($d['tender_number']) . ') by <strong>' . e($d['contractor_name']) . '</strong>.</p>
      <p><strong>Agreement amount:</strong> ' . e($d['amount']) . ' &nbsp;&nbsp; <strong>Completion period:</strong> ' . e($d['completion_period']) . '</p>
      <p><strong>Security deposit:</strong> ' . e($d['security_deposit']) . '</p>
      <h3>Conditions</h3><p>' . e($d['conditions']) . '</p>
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
