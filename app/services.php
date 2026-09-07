<?php
/**
 * Domain services: financial years, numbering, financial math, contractors,
 * tenders, bids, awards, execution/measurement/bills/payments/completion, and
 * reports. Money is always integer minor units.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/compliance.php';

// ===========================================================================
// Financial years
// ===========================================================================
function fy_current(): ?array
{
    $cur = DB::one('SELECT * FROM financial_years WHERE is_current = 1');
    if ($cur) {
        return $cur;
    }
    $label = fy_label(today_iso());
    $f = DB::one('SELECT * FROM financial_years WHERE label = ?', [$label]);
    if ($f) {
        return $f;
    }
    return DB::one('SELECT * FROM financial_years ORDER BY start_date DESC LIMIT 1');
}

function fy_list(): array
{
    return DB::all('SELECT * FROM financial_years ORDER BY start_date DESC');
}

function fy_resolve_for_date(string $dateISO): array
{
    $label = fy_label($dateISO);
    $f = DB::one('SELECT * FROM financial_years WHERE label = ?', [$label]);
    if ($f) {
        return $f;
    }
    $parsed = parse_fy($label);
    $id = DB::insert(
        'INSERT INTO financial_years (uid, label, start_year, start_date, end_date, status, is_current) VALUES (?,?,?,?,?,?,0)',
        [uid(), $label, $parsed['startYear'], $parsed['start'], $parsed['end'], 'open']
    );
    return DB::one('SELECT * FROM financial_years WHERE id = ?', [$id]);
}

function fy_close(int $id): array
{
    $f = DB::one('SELECT * FROM financial_years WHERE id = ?', [$id]);
    if (!$f) {
        throw not_found('Financial year not found');
    }
    if ($f['status'] === 'closed') {
        throw bad_request('Financial year already closed');
    }
    $open = (int) DB::val("SELECT COUNT(*) FROM tenders WHERE fy_id = ? AND status NOT IN ('closed','cancelled')", [$id])
        + (int) DB::val("SELECT COUNT(*) FROM bills WHERE fy_id = ? AND status NOT IN ('paid','rejected','returned')", [$id]);
    if ($open > 0) {
        throw conflict('Cannot close financial year while open tenders or pending bills exist', ['openCount' => $open]);
    }
    DB::run("UPDATE financial_years SET status = 'closed', is_current = 0 WHERE id = ?", [$id]);
    return DB::one('SELECT * FROM financial_years WHERE id = ?', [$id]);
}

function fy_next_serial(string $scope, ?int $fyId, ?int $panchayatId = null, string $prefix = ''): int
{
    return DB::tx(function () use ($scope, $fyId, $panchayatId, $prefix) {
        $existing = DB::one(
            'SELECT * FROM numbering_sequences WHERE scope = ? AND fy_id = ? AND prefix = ?',
            [$scope, $fyId, $prefix]
        );
        if ($existing) {
            $next = (int) $existing['last_value'] + 1;
            DB::run('UPDATE numbering_sequences SET last_value = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$next, (int) $existing['id']]);
        } else {
            $next = 1;
            DB::insert(
                'INSERT INTO numbering_sequences (scope, fy_id, panchayat_id, prefix, last_value) VALUES (?,?,?,?,1)',
                [$scope, $fyId, $panchayatId, $prefix]
            );
        }
        return $next;
    });
}

// ===========================================================================
// Numbering
// ===========================================================================
function num_setting(string $key, string $def): string
{
    $r = DB::one('SELECT ' . sql_ident('value') . ' FROM settings WHERE ' . sql_ident('key') . ' = ?', [$key]);
    return ($r && $r['value'] !== null && $r['value'] !== '') ? $r['value'] : $def;
}

function num_fy_label(?int $fyId): string
{
    $f = DB::one('SELECT label FROM financial_years WHERE id = ?', [$fyId]);
    return $f ? $f['label'] : '????';
}

function num_render(string $pattern, ?int $fyId, int $serial): string
{
    return strtr($pattern, [
        '{PREFIX}' => num_setting('numbering.prefix', 'GP'),
        '{FY}' => num_fy_label($fyId),
        '{NNN}' => str_pad((string) $serial, 3, '0', STR_PAD_LEFT),
        '{N}' => (string) $serial,
    ]);
}

function num_next(string $scope, ?int $fyId, string $prefix = '', ?string $pattern = null): string
{
    $serial = fy_next_serial($scope, $fyId, null, $prefix);
    return num_render($pattern ?: num_setting("numbering.$scope.pattern", '{PREFIX}/NIT/{FY}/{NNN}'), $fyId, $serial);
}

function num_next_tender_number(?int $fyId): string
{
    for ($i = 0; $i < 10; $i++) {
        $number = num_next('tender_nit', $fyId, '', num_setting('numbering.tender_nit.pattern', '{PREFIX}/NIT/{FY}/{NNN}'));
        if (!DB::one('SELECT id FROM tenders WHERE tender_number = ?', [$number])) {
            return $number;
        }
    }
    throw conflict('Could not allocate a unique tender number');
}

function num_next_bill_number(?int $fyId): string
{
    return num_next('bill', $fyId, '', num_setting('numbering.bill.pattern', 'BILL/{FY}/{NNN}'));
}

function num_next_work_order(?int $fyId): string
{
    return num_next('work_order', $fyId, '', num_setting('numbering.work_order.pattern', 'WO/{FY}/{NNN}'));
}

function num_next_agreement(?int $fyId): string
{
    return num_next('agreement', $fyId, '', num_setting('numbering.agreement.pattern', 'AGR/{FY}/{NNN}'));
}

function num_next_loa(?int $fyId): string
{
    return num_next('loa', $fyId, '', num_setting('numbering.loa.pattern', 'LOA/{FY}/{NNN}'));
}

function num_next_contractor_code(): string
{
    $max = (int) DB::val('SELECT MAX(id) FROM contractors');
    return 'CT/' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function num_next_measurement(int $projectId): string
{
    $c = (int) DB::val('SELECT COUNT(*) FROM measurements WHERE project_id = ?', [$projectId]);
    return 'MB-' . str_pad((string) ($c + 1), 3, '0', STR_PAD_LEFT);
}

function num_next_corrigendum(int $tenderId): string
{
    $c = (int) DB::val('SELECT COUNT(*) FROM corrigenda WHERE tender_id = ?', [$tenderId]);
    return 'CORR-' . ($c + 1);
}

// ===========================================================================
// Financial math
// ===========================================================================
function fin_boq_totals(int $tenderId): array
{
    $items = DB::all('SELECT * FROM boq_items WHERE tender_id = ?', [$tenderId]);
    $base = 0;
    $tax = 0;
    foreach ($items as $it) {
        $b = qty_rate($it['quantity'], $it['estimated_rate_minor']);
        $base += $b;
        if ((float) $it['tax_pct'] > 0) {
            $tax += pct_of($b, $it['tax_pct']);
        }
    }
    return ['items' => $items, 'count' => count($items), 'baseMinor' => $base, 'taxMinor' => $tax, 'totalMinor' => $base + $tax];
}

function fin_compute_bill(array $in): array
{
    $gross = (int) round((float) ($in['grossWorkValueMinor'] ?? 0));
    $prev = (int) round((float) ($in['previousCertifiedMinor'] ?? 0));
    $current = $gross - $prev;
    $retention = (float) ($in['retentionPct'] ?? 0) > 0 ? pct_of($current, $in['retentionPct']) : 0;
    $ded = (int) round((float) ($in['deductionsMinor'] ?? 0)) + (int) round((float) ($in['recoveriesMinor'] ?? 0));
    $tax = (int) round((float) ($in['taxAmountMinor'] ?? 0));
    $net = max(0, $current - $retention - $ded);
    return [
        'grossMinor' => $gross,
        'previousCertifiedMinor' => $prev,
        'currentMinor' => $current,
        'cumulativeMinor' => $gross,
        'retentionMinor' => $retention,
        'taxMinor' => $tax,
        'deductionsMinor' => $ded,
        'netPayableMinor' => $net,
    ];
}

function fin_rank_bids(int $tenderId): array
{
    $bids = DB::all(
        'SELECT fb.*, tb.contractor_id, tb.bid_status
           FROM financial_bids fb JOIN tender_bidders tb ON tb.id = fb.bidder_id
          WHERE fb.tender_id = ?',
        [$tenderId]
    );
    usort($bids, function ($a, $b) { return ((int) $a['total_amount_minor']) <=> ((int) $b['total_amount_minor']); });
    $ranked = [];
    $rank = 1;
    foreach ($bids as $b) {
        DB::run('UPDATE tender_bidders SET ' . sql_ident('rank') . ' = ? WHERE id = ?', [$rank, (int) $b['bidder_id']]);
        $ranked[] = ['bidder_id' => (int) $b['bidder_id'], 'contractor_id' => (int) $b['contractor_id'], 'totalMinor' => (int) $b['total_amount_minor'], 'rank' => $rank];
        $rank++;
    }
    return $ranked;
}

// ===========================================================================
// Contractors
// ===========================================================================
function contractor_get(int $id): array
{
    $c = DB::one('SELECT * FROM contractors WHERE id = ?', [$id]);
    if (!$c) {
        throw not_found('Contractor not found');
    }
    return $c;
}

function contractor_expiry_status(?string $expiryDate): string
{
    if (!$expiryDate) {
        return 'verification_required';
    }
    $days = days_between(today_iso(), $expiryDate);
    if ($days === null) {
        return 'verification_required';
    }
    $warn = (int) (DB::val('SELECT ' . sql_ident('value') . ' FROM settings WHERE ' . sql_ident('key') . " = 'contractor.expiry_warning_days'") ?: 60);
    if ($days < 0) {
        return 'expired';
    }
    if ($days <= $warn) {
        return 'expiring_soon';
    }
    return 'valid';
}

function contractor_create(array $data, array $actor): array
{
    if (empty($data['legal_name'])) {
        throw validation('Legal name is required');
    }
    $code = num_next_contractor_code();
    $id = DB::insert(
        'INSERT INTO contractors
           (uid, contractor_code, legal_name, business_name, address, mobile, email, registration_no, registration_class,
            registration_valid_from, registration_valid_to, pan, gst, bank_name, bank_account_no, bank_ifsc,
            experience_summary, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            uid(), $code, $data['legal_name'], $data['business_name'] ?? null, $data['address'] ?? null, $data['mobile'] ?? null,
            $data['email'] ?? null, $data['registration_no'] ?? null, $data['registration_class'] ?? null,
            $data['registration_valid_from'] ?? null, $data['registration_valid_to'] ?? null, $data['pan'] ?? null, $data['gst'] ?? null,
            $data['bank_name'] ?? null, $data['bank_account_no'] ?? null, $data['bank_ifsc'] ?? null,
            $data['experience_summary'] ?? null, $data['status'] ?? 'active', (int) $actor['id'],
        ]
    );
    audit_record('contractor.create', 'contractor', $id, $data['legal_name']);
    return contractor_get($id);
}

function contractor_upsert_document(int $contractorId, array $data, array $actor): array
{
    contractor_get($contractorId);
    $status = contractor_expiry_status($data['expiry_date'] ?? null);
    $id = DB::insert(
        'INSERT INTO contractor_documents (uid, contractor_id, doc_type, doc_name, issued_date, expiry_date, expiry_status, remarks)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), $contractorId, $data['doc_type'] ?? 'other', $data['doc_name'] ?? 'Document', $data['issued_date'] ?? null, $data['expiry_date'] ?? null, $status, $data['remarks'] ?? null]
    );
    audit_record('contractor.document.add', 'contractor', $contractorId, $data['doc_name'] ?? null);
    return DB::one('SELECT * FROM contractor_documents WHERE id = ?', [$id]);
}

function contractor_refresh_expiry(): int
{
    $n = 0;
    foreach (DB::all('SELECT * FROM contractor_documents') as $d) {
        DB::run('UPDATE contractor_documents SET expiry_status = ? WHERE id = ?', [contractor_expiry_status($d['expiry_date']), (int) $d['id']]);
        $n++;
    }
    return $n;
}

// ===========================================================================
// Tenders
// ===========================================================================
function tender_get(int $id): array
{
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [$id]);
    if (!$t) {
        throw not_found('Tender not found');
    }
    return $t;
}

function tender_snapshot(int $tenderId, int $versionNo, string $changeType, string $reason, array $tender, array $actor): void
{
    DB::insert(
        'INSERT INTO tender_versions (uid, tender_id, version_no, change_type, reason, snapshot, changed_by) VALUES (?,?,?,?,?,?,?)',
        [uid(), $tenderId, $versionNo, $changeType, $reason, json_store($tender), (int) $actor['id']]
    );
}

function tender_next_version(int $tenderId): int
{
    return (int) DB::val('SELECT MAX(version_no) FROM tender_versions WHERE tender_id = ?', [$tenderId]) + 1;
}

function tender_validate_dates(array $t): void
{
    $seq = [['bid_start_date', 'bid_close_date'], ['bid_close_date', 'technical_open_date'], ['technical_open_date', 'financial_open_date']];
    foreach ($seq as [$a, $b]) {
        if (!empty($t[$a]) && !empty($t[$b]) && is_after($t[$a], $t[$b])) {
            throw validation("$a must not be after $b");
        }
    }
}

function tender_create(array $data, array $actor): array
{
    $fy = DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) $data['fy_id']]);
    if (!$fy) {
        throw validation('A financial year must be selected');
    }
    if ($fy['status'] === 'closed') {
        throw conflict('Cannot create a tender in a closed financial year');
    }
    if (empty($data['procurement_category']) || empty($data['tender_type'])) {
        throw validation('Procurement category and tender type are required');
    }
    $number = num_next_tender_number((int) $fy['id']);
    $id = DB::insert(
        'INSERT INTO tenders
           (uid, fy_id, project_id, scheme_id, fund_id, ruleset_id, tender_number, tender_type, procurement_category,
            procurement_method, title, description, location, work_name, status, workflow_stage, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            uid(), (int) $fy['id'], int_or_null($data['project_id'] ?? null), int_or_null($data['scheme_id'] ?? null), int_or_null($data['fund_id'] ?? null),
            int_or_null($data['ruleset_id'] ?? null), $number, $data['tender_type'], $data['procurement_category'],
            $data['procurement_method'] ?? null, $data['title'] ?: ($data['work_name'] ?? 'Untitled tender'),
            $data['description'] ?? null, $data['location'] ?? null, $data['work_name'] ?? ($data['title'] ?? null),
            'draft', 'draft', (int) $actor['id'],
        ]
    );
    $tender = tender_get($id);
    tender_snapshot($id, 1, 'create', 'Tender created', $tender, $actor);
    audit_record('tender.create', 'tender', $id, $number);
    return $tender;
}

const TENDER_EDITABLE_FIELDS = [
    'project_id', 'scheme_id', 'fund_id', 'ruleset_id', 'tender_type', 'procurement_category', 'procurement_method',
    'title', 'description', 'location', 'work_name',
    'admin_approval_no', 'admin_approval_date', 'admin_approval_authority', 'admin_approval_amount_minor',
    'tech_sanction_no', 'tech_sanction_date', 'tech_sanction_authority', 'tech_sanction_amount_minor',
    'estimated_cost_minor', 'tender_value_minor', 'emd_minor', 'emd_exemption_notes', 'tender_fee_minor',
    'security_deposit_minor', 'security_deposit_pct', 'tax_config', 'budget_provision', 'head_of_account',
    'completion_period_days', 'technical_specification', 'eligibility_notes',
    'general_conditions', 'special_conditions', 'payment_conditions', 'completion_conditions',
    'extension_conditions', 'penalty_provisions', 'defect_liability',
    'publication_date', 'bid_start_date', 'bid_close_date', 'technical_open_date', 'financial_open_date', 'bid_validity_days',
];

function tender_update(int $id, array $fields, array $actor): array
{
    $t = tender_get($id);
    if ($t['status'] !== 'draft') {
        throw conflict('Tender is in "' . $t['status'] . '" status and is locked. Use the corrigendum process for changes.');
    }
    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, TENDER_EDITABLE_FIELDS, true)) {
            continue;
        }
        $sets[] = "$k = ?";
        $params[] = $v === '' ? null : $v;
    }
    if ($sets) {
        $params[] = $id;
        DB::run('UPDATE tenders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }
    $fresh = tender_get($id);
    tender_validate_dates($fresh);
    tender_snapshot($id, tender_next_version($id), 'update', 'Tender fields updated', $fresh, $actor);
    audit_record('tender.update', 'tender', $id, $fresh['tender_number']);
    return $fresh;
}

function tender_run_compliance(int $id, array $actor): array
{
    return compliance_evaluate_tender(tender_get($id), ['persist' => true, 'actorId' => (int) $actor['id']]);
}

function tender_submit(int $id, array $actor): array
{
    $t = tender_get($id);
    if ($t['status'] !== 'draft') {
        throw conflict('Only draft tenders can be submitted for approval');
    }
    if (empty($t['admin_approval_no']) || empty($t['tech_sanction_no'])) {
        throw conflict('Administrative approval and technical sanction must be recorded before submission');
    }
    if ((int) DB::val('SELECT COUNT(*) FROM boq_items WHERE tender_id = ?', [$id]) === 0) {
        throw conflict('At least one BOQ item is required before submission');
    }
    DB::run("UPDATE tenders SET status = 'under_approval', workflow_stage = 'under_approval' WHERE id = ?", [$id]);
    workflow_init('tender', $id, ['reset' => true]);
    tender_snapshot($id, tender_next_version($id), 'submit', 'Submitted for approval', tender_get($id), $actor);
    audit_record('tender.submit', 'tender', $id, $t['tender_number']);
    notify('approval_pending', 'Tender submitted for approval', $t['tender_number'] . ' is awaiting technical verification.', null, ['technical_officer'], 'tender', $id);
    return tender_get($id);
}

function tender_workflow_action(int $id, string $action, ?string $remarks, array $actor): array
{
    $t = tender_get($id);
    if ($t['status'] !== 'under_approval') {
        throw conflict('Tender is not in approval workflow');
    }
    $result = workflow_act('tender', $id, $action, $remarks, $actor, function () use ($id, $t, $actor) {
        DB::run("UPDATE tenders SET status = 'approved', workflow_stage = 'approved' WHERE id = ?", [$id]);
        tender_snapshot($id, tender_next_version($id), 'approve', 'Tender approved', tender_get($id), $actor);
        notify('tender_approved', 'Tender approved', $t['tender_number'] . ' has been approved and can proceed to NIT generation.', null, ['panchayat_secretary', 'pradhan'], 'tender', $id);
    });
    if (in_array($action, ['reject', 'return', 'clarification'], true)) {
        DB::run("UPDATE tenders SET status = 'draft', workflow_stage = 'draft' WHERE id = ?", [$id]);
        tender_snapshot($id, tender_next_version($id), $action, 'Workflow ' . $action . ': ' . ($remarks ?? ''), tender_get($id), $actor);
        notify('tender_returned', 'Tender returned', $t['tender_number'] . ' was ' . ($action === 'reject' ? 'rejected' : 'returned') . ': ' . ($remarks ?? ''), null, ['panchayat_secretary'], 'tender', $id);
    }
    return ['workflow' => $result, 'tender' => tender_get($id)];
}

function tender_generate_nit(int $id, array $actor): array
{
    $t = tender_get($id);
    if (!in_array($t['status'], ['approved', 'nit_generated'], true)) {
        throw conflict('Tender must be approved before NIT generation');
    }
    $doc = document_generate_nit($id, $actor);
    $vn = (int) DB::val('SELECT MAX(version_no) FROM nit_versions WHERE tender_id = ?', [$id]) + 1;
    DB::insert(
        'INSERT INTO nit_versions (uid, tender_id, version_no, content_json, generated_by, document_id) VALUES (?,?,?,?,?,?)',
        [uid(), $id, $vn, json_store(doc_nit_merge_data($id)), (int) $actor['id'], $doc['id']]
    );
    DB::run("UPDATE tenders SET nit_number = COALESCE(nit_number, tender_number), status = CASE WHEN status = 'approved' THEN 'nit_generated' ELSE status END WHERE id = ?", [$id]);
    tender_snapshot($id, tender_next_version($id), 'nit_generate', 'NIT generated', tender_get($id), $actor);
    audit_record('nit.generate', 'tender', $id, $t['tender_number']);
    return ['document' => $doc, 'tender' => tender_get($id)];
}

function tender_publish(int $id, array $data, array $actor): array
{
    $t = tender_get($id);
    if (!in_array($t['status'], ['approved', 'nit_generated'], true)) {
        throw conflict('Tender must be approved (and NIT generated) before publication');
    }
    $pubDate = $data['publication_date'] ?? $t['publication_date'] ?? today_iso();
    DB::run("UPDATE tenders SET status = 'published', publication_date = ?, workflow_stage = 'published' WHERE id = ?", [$pubDate, $id]);
    if (!empty($data['official_portal']) || !empty($data['external_tender_id'])) {
        DB::insert(
            'INSERT INTO external_refs (uid, entity_type, entity_id, official_portal, external_tender_id, external_reference_no, publication_status, official_url, sync_method)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [uid(), 'tender', $id, $data['official_portal'] ?? null, $data['external_tender_id'] ?? null, $data['external_reference_no'] ?? null, $data['publication_status'] ?? null, $data['official_url'] ?? null, $data['sync_method'] ?? 'manual']
        );
    }
    tender_snapshot($id, tender_next_version($id), 'publish', 'Tender published', tender_get($id), $actor);
    audit_record('tender.publish', 'tender', $id, $t['tender_number']);
    return tender_get($id);
}

function tender_start_bidding(int $id, array $actor): array
{
    $t = tender_get($id);
    if ($t['status'] !== 'published') {
        throw conflict('Tender must be published before bidding');
    }
    DB::run("UPDATE tenders SET status = 'bidding' WHERE id = ?", [$id]);
    audit_record('tender.start_bidding', 'tender', $id, $t['tender_number']);
    return tender_get($id);
}

function tender_close_bids(int $id, array $actor): array
{
    $t = tender_get($id);
    if (!in_array($t['status'], ['published', 'bidding'], true)) {
        throw conflict('Tender is not in bidding');
    }
    DB::run("UPDATE tenders SET status = 'bid_closed' WHERE id = ?", [$id]);
    audit_record('tender.close_bids', 'tender', $id, $t['tender_number']);
    return tender_get($id);
}

function tender_cancel(int $id, array $data, array $actor): array
{
    $t = tender_get($id);
    if ($t['status'] === 'cancelled') {
        throw conflict('Tender is already cancelled');
    }
    if (in_array($t['status'], ['awarded', 'closed'], true)) {
        throw conflict('Cannot cancel an awarded/closed tender');
    }
    if (empty($data['reason'])) {
        throw validation('Cancellation reason is required');
    }
    DB::insert(
        'INSERT INTO tender_cancellations (uid, tender_id, reason, authority, cancel_date, created_by) VALUES (?,?,?,?,?,?)',
        [uid(), $id, $data['reason'], $data['authority'] ?? null, $data['cancel_date'] ?? today_iso(), (int) $actor['id']]
    );
    DB::run("UPDATE tenders SET status = 'cancelled' WHERE id = ?", [$id]);
    DB::run("UPDATE approval_steps SET status = 'cancelled' WHERE entity_type = 'tender' AND entity_id = ? AND status IN ('pending','in_progress')", [$id]);
    tender_snapshot($id, tender_next_version($id), 'cancel', $data['reason'], tender_get($id), $actor);
    audit_record('tender.cancel', 'tender', $id, $t['tender_number'], ['reason' => $data['reason']]);
    return tender_get($id);
}

function tender_corrigendum(int $id, array $data, array $actor): array
{
    $t = tender_get($id);
    if (in_array($t['status'], ['cancelled', 'closed'], true)) {
        throw conflict('Cannot corrigendum a cancelled/closed tender');
    }
    if (empty($data['changes']) || !is_array($data['changes'])) {
        throw validation('At least one changed field is required');
    }
    $num = num_next_corrigendum($id);
    $cid = DB::insert(
        'INSERT INTO corrigenda (uid, tender_id, corrigendum_number, reason, changes, new_dates, created_by) VALUES (?,?,?,?,?,?,?)',
        [uid(), $id, $num, $data['reason'] ?? '', json_store($data['changes']), json_store($data['new_dates'] ?? []), (int) $actor['id']]
    );
    tender_snapshot($id, tender_next_version($id), 'corrigendum', "Corrigendum $num", $t, $actor);
    audit_record('tender.corrigendum', 'tender', $id, $t['tender_number'] . ' (' . $num . ')', ['reason' => $data['reason'] ?? null]);
    return DB::one('SELECT * FROM corrigenda WHERE id = ?', [$cid]);
}

function tender_retender(int $id, array $data, array $actor): array
{
    $t = tender_get($id);
    if (in_array($t['status'], ['retendered', 'awarded', 'closed'], true)) {
        throw conflict('Cannot re-tender from this status');
    }
    $number = num_next_tender_number((int) $t['fy_id']);
    $newId = DB::insert(
        'INSERT INTO tenders
           (uid, fy_id, project_id, scheme_id, fund_id, ruleset_id, tender_number, tender_type, procurement_category,
            procurement_method, title, description, location, work_name,
            admin_approval_no, admin_approval_date, admin_approval_authority, admin_approval_amount_minor,
            tech_sanction_no, tech_sanction_date, tech_sanction_authority, tech_sanction_amount_minor,
            estimated_cost_minor, tender_value_minor, emd_minor, tender_fee_minor, security_deposit_minor, security_deposit_pct,
            completion_period_days, technical_specification, eligibility_notes, general_conditions, special_conditions,
            payment_conditions, completion_conditions, extension_conditions, penalty_provisions, defect_liability,
            status, workflow_stage, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            uid(), $t['fy_id'], $t['project_id'], $t['scheme_id'], $t['fund_id'], $t['ruleset_id'], $number, $t['tender_type'], $t['procurement_category'],
            $t['procurement_method'], $t['title'] . ' (Re-Tender)', $t['description'], $t['location'], $t['work_name'],
            $t['admin_approval_no'], $t['admin_approval_date'], $t['admin_approval_authority'], $t['admin_approval_amount_minor'],
            $t['tech_sanction_no'], $t['tech_sanction_date'], $t['tech_sanction_authority'], $t['tech_sanction_amount_minor'],
            $t['estimated_cost_minor'], $t['tender_value_minor'], $t['emd_minor'], $t['tender_fee_minor'], $t['security_deposit_minor'], $t['security_deposit_pct'],
            $t['completion_period_days'], $t['technical_specification'], $t['eligibility_notes'], $t['general_conditions'], $t['special_conditions'],
            $t['payment_conditions'], $t['completion_conditions'], $t['extension_conditions'], $t['penalty_provisions'], $t['defect_liability'],
            'draft', 'draft', (int) $actor['id'],
        ]
    );
    foreach (DB::all('SELECT * FROM boq_items WHERE tender_id = ?', [$id]) as $item) {
        DB::insert(
            'INSERT INTO boq_items (uid, tender_id, item_no, group_name, description, specification, unit, quantity, estimated_rate_minor, tax_pct, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [uid(), $newId, $item['item_no'], $item['group_name'], $item['description'], $item['specification'], $item['unit'], $item['quantity'], $item['estimated_rate_minor'], $item['tax_pct'], $item['sort_order']]
        );
    }
    DB::insert(
        'INSERT INTO retenders (uid, original_tender_id, new_tender_id, reason, carry_forward, created_by) VALUES (?,?,?,?,?,?)',
        [uid(), $id, $newId, $data['reason'] ?? null, json_store($data['carry_forward'] ?? []), (int) $actor['id']]
    );
    DB::run("UPDATE tenders SET status = 'retendered' WHERE id = ?", [$id]);
    tender_snapshot($id, tender_next_version($id), 'retender', 'Re-tendered to ' . $number, $t, $actor);
    tender_snapshot($newId, 1, 'create', 'Created as re-tender of ' . $t['tender_number'], tender_get($newId), $actor);
    audit_record('tender.retender', 'tender', $id, $t['tender_number'] . ' -> ' . $number, ['reason' => $data['reason'] ?? null]);
    return tender_get($newId);
}

// ===========================================================================
// Bids & evaluation
// ===========================================================================
function bidder_list(int $tenderId): array
{
    return DB::all(
        'SELECT b.*, c.legal_name, c.business_name, c.contractor_code, c.registration_class
           FROM tender_bidders b JOIN contractors c ON c.id = b.contractor_id
          WHERE b.tender_id = ? ORDER BY b.id',
        [$tenderId]
    );
}

function bidder_add(int $tenderId, array $data, array $actor): array
{
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
    if (!$t) {
        throw not_found('Tender not found');
    }
    if (!in_array($t['status'], ['bidding', 'bid_closed', 'technical_evaluation'], true)) {
        throw conflict('Bidders can only be recorded once bidding has started');
    }
    $contractorId = (int) ($data['contractor_id'] ?? 0);
    $c = contractor_get($contractorId);
    if ($c['status'] === 'debarred') {
        throw conflict('Contractor is debarred and cannot bid');
    }
    if (DB::one('SELECT id FROM tender_bidders WHERE tender_id = ? AND contractor_id = ?', [$tenderId, $contractorId])) {
        throw conflict('Contractor is already recorded as a bidder');
    }
    $id = DB::insert(
        'INSERT INTO tender_bidders (uid, tender_id, contractor_id, bidder_label, submission_time, emd_paid_minor, emd_details, created_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), $tenderId, $contractorId, $data['bidder_label'] ?? $c['legal_name'], $data['submission_time'] ?? now_iso(), minor($data['emd_paid_minor'] ?? null), $data['emd_details'] ?? null, (int) $actor['id']]
    );
    audit_record('bid.record', 'tender_bidder', $id, $c['legal_name']);
    return DB::one('SELECT * FROM tender_bidders WHERE id = ?', [$id]);
}

function bidder_technical_open(int $tenderId, array $data, array $actor): array
{
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [$tenderId]);
    if (!$t) {
        throw not_found('Tender not found');
    }
    if ($t['status'] !== 'bid_closed') {
        throw conflict('Bids must be closed before technical opening');
    }
    $id = DB::insert(
        'INSERT INTO technical_openings (uid, tender_id, opening_date, opened_by, attendees, observations, status) VALUES (?,?,?,?,?,?,?)',
        [uid(), $tenderId, $data['opening_date'] ?? today_iso(), (int) $actor['id'], json_store($data['attendees'] ?? []), $data['observations'] ?? null, 'completed']
    );
    DB::run("UPDATE tenders SET status = 'technical_evaluation' WHERE id = ?", [$tenderId]);
    DB::run("UPDATE tender_bidders SET bid_status = 'technical_opened' WHERE tender_id = ? AND bid_status = 'submitted'", [$tenderId]);
    audit_record('bid.technical_open', 'tender', $tenderId, $t['tender_number']);
    return DB::one('SELECT * FROM technical_openings WHERE id = ?', [$id]);
}

function criteria_list(int $tenderId): array
{
    return DB::all('SELECT * FROM evaluation_criteria WHERE tender_id = ? ORDER BY sort_order, id', [$tenderId]);
}

function criteria_add(int $tenderId, array $data): array
{
    $max = (int) DB::val('SELECT COALESCE(MAX(sort_order),0) FROM evaluation_criteria WHERE tender_id = ?', [$tenderId]);
    $id = DB::insert(
        'INSERT INTO evaluation_criteria (uid, tender_id, code, criterion, requirement, is_required, sort_order) VALUES (?,?,?,?,?,?,?)',
        [uid(), $tenderId, $data['code'] ?? ('C' . time()), $data['criterion'] ?? 'Criterion', $data['requirement'] ?? null, !empty($data['is_required']) ? 1 : 0, $max + 1]
    );
    return DB::one('SELECT * FROM evaluation_criteria WHERE id = ?', [$id]);
}

function evaluation_set(int $tenderId, int $bidderId, int $criterionId, array $data, array $actor): array
{
    $existing = DB::one('SELECT id FROM technical_evaluations WHERE bidder_id = ? AND criterion_id = ?', [$bidderId, $criterionId]);
    if ($existing) {
        DB::run(
            'UPDATE technical_evaluations SET result = ?, bidder_response = ?, remarks = ?, evaluated_by = ?, evaluated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$data['result'] ?? 'pass', $data['bidder_response'] ?? null, $data['remarks'] ?? null, (int) $actor['id'], (int) $existing['id']]
        );
        return DB::one('SELECT * FROM technical_evaluations WHERE id = ?', [(int) $existing['id']]);
    }
    $id = DB::insert(
        'INSERT INTO technical_evaluations (uid, tender_id, bidder_id, criterion_id, result, bidder_response, remarks, evaluated_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), $tenderId, $bidderId, $criterionId, $data['result'] ?? 'pass', $data['bidder_response'] ?? null, $data['remarks'] ?? null, (int) $actor['id']]
    );
    return DB::one('SELECT * FROM technical_evaluations WHERE id = ?', [$id]);
}

function evaluation_finalize(int $tenderId, array $data, array $actor): array
{
    $t = tender_get($tenderId);
    if ($t['status'] !== 'technical_evaluation') {
        throw conflict('Tender is not in technical evaluation');
    }
    $bidders = bidder_list($tenderId);
    $criteria = criteria_list($tenderId);
    if (!$criteria) {
        throw conflict('No evaluation criteria configured');
    }
    $reasons = $data['rejection_reasons'] ?? [];
    foreach ($bidders as $b) {
        $evals = DB::all('SELECT * FROM technical_evaluations WHERE bidder_id = ?', [(int) $b['id']]);
        $fail = [];
        $verify = [];
        foreach ($evals as $e) {
            if ($e['result'] === 'fail') {
                $fail[] = $e;
            } elseif ($e['result'] === 'verification_required') {
                $verify[] = $e;
            }
        }
        if ($verify) {
            $status = 'clarification_required';
            $reason = 'Verification required on one or more criteria';
        } elseif ($fail) {
            $status = 'technically_disqualified';
            $reason = $reasons[(int) $b['id']] ?? null;
            if (!$reason) {
                throw conflict('Rejection reason required for bidder ' . $b['legal_name']);
            }
        } else {
            $status = 'technically_qualified';
            $reason = null;
        }
        DB::run('UPDATE tender_bidders SET bid_status = ?, rejection_reason = ? WHERE id = ?', [$status, $reason, (int) $b['id']]);
    }
    DB::run("UPDATE tenders SET status = 'financial_evaluation' WHERE id = ?", [$tenderId]);
    audit_record('evaluation.technical_finalize', 'tender', $tenderId, $t['tender_number']);
    return ['bidders' => bidder_list($tenderId), 'criteria' => $criteria];
}

function financial_bid_record(int $tenderId, int $bidderId, array $data, array $actor): array
{
    $b = DB::one('SELECT * FROM tender_bidders WHERE id = ?', [$bidderId]);
    if (!$b || (int) $b['tender_id'] !== $tenderId) {
        throw validation('Bidder does not belong to this tender');
    }
    if ($b['bid_status'] !== 'technically_qualified') {
        throw conflict('Only technically qualified bidders can have financial bids opened');
    }
    $total = (int) round((float) ($data['total_amount_minor'] ?? 0));
    $base = (int) round((float) ($data['base_amount_minor'] ?? 0));
    $tax = (int) round((float) ($data['tax_amount_minor'] ?? 0));
    $disc = (int) round((float) ($data['discount_minor'] ?? 0));
    $existing = DB::one('SELECT id FROM financial_bids WHERE tender_id = ? AND bidder_id = ?', [$tenderId, $bidderId]);
    if ($existing) {
        DB::run(
            'UPDATE financial_bids SET total_amount_minor = ?, base_amount_minor = ?, tax_amount_minor = ?, discount_minor = ?, quoted_on = CURRENT_TIMESTAMP WHERE id = ?',
            [$total, $base, $tax, $disc, (int) $existing['id']]
        );
        $fbId = (int) $existing['id'];
        DB::run('DELETE FROM financial_bid_items WHERE financial_bid_id = ?', [$fbId]);
    } else {
        $fbId = DB::insert(
            'INSERT INTO financial_bids (uid, tender_id, bidder_id, total_amount_minor, base_amount_minor, tax_amount_minor, discount_minor, created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [uid(), $tenderId, $bidderId, $total, $base, $tax, $disc, (int) $actor['id']]
        );
    }
    foreach (($data['items'] ?? []) as $it) {
        DB::insert(
            'INSERT INTO financial_bid_items (uid, financial_bid_id, boq_item_id, item_no, bidder_rate_minor, quantity, amount_minor)
             VALUES (?,?,?,?,?,?,?)',
            [uid(), $fbId, $it['boq_item_id'] ?? null, $it['item_no'] ?? null, (int) round((float) ($it['bidder_rate_minor'] ?? 0)), (float) ($it['quantity'] ?? 0), (int) round((float) ($it['amount_minor'] ?? 0))]
        );
    }
    DB::run("UPDATE tender_bidders SET bid_status = 'financial_opened' WHERE id = ?", [$bidderId]);
    audit_record('evaluation.financial_record', 'tender_bidder', $bidderId, $b['bidder_label'] . ' financial bid');
    return DB::one('SELECT * FROM financial_bids WHERE id = ?', [$fbId]);
}

function ranking_compute(int $tenderId, array $actor): array
{
    $t = tender_get($tenderId);
    $ranked = fin_rank_bids($tenderId);
    audit_record('evaluation.rank', 'tender', $tenderId, $t['tender_number'], ['newValue' => $ranked]);
    return $ranked;
}

function comparative_statement(int $tenderId): array
{
    $t = tender_get($tenderId);
    $bidders = bidder_list($tenderId);
    $fin = [];
    foreach (DB::all('SELECT * FROM financial_bids WHERE tender_id = ?', [$tenderId]) as $f) {
        $fin[(int) $f['bidder_id']] = $f;
    }
    $rows = [];
    foreach ($bidders as $b) {
        $f = $fin[(int) $b['id']] ?? null;
        $total = $f ? (int) $f['total_amount_minor'] : null;
        $variation = ($total !== null && $t['estimated_cost_minor']) ? pct_diff($t['estimated_cost_minor'], $total) : null;
        $rows[] = [
            'bidderId' => (int) $b['id'],
            'bidder' => $b['legal_name'] ?: $b['business_name'] ?: $b['bidder_label'],
            'qualification' => $b['bid_status'],
            'rank' => $b['rank'] !== null ? (int) $b['rank'] : null,
            'totalMinor' => $total,
            'totalFmt' => $total !== null ? inr($total) : '—',
            'variation' => $variation !== null ? round($variation, 2) : null,
            'rejectionReason' => $b['rejection_reason'],
        ];
    }
    return ['tender' => $t, 'estimatedMinor' => (int) $t['estimated_cost_minor'], 'rows' => $rows];
}

// ===========================================================================
// Awards
// ===========================================================================
function award_get(int $id): array
{
    $a = DB::one('SELECT * FROM awards WHERE id = ?', [$id]);
    if (!$a) {
        throw not_found('Award not found');
    }
    return $a;
}

function award_recommend(int $tenderId, array $data, array $actor): array
{
    $t = tender_get($tenderId);
    if ($t['status'] !== 'financial_evaluation') {
        throw conflict('Financial evaluation must be complete before award recommendation');
    }
    $contractorId = (int) ($data['contractor_id'] ?? 0);
    $b = DB::one('SELECT * FROM tender_bidders WHERE tender_id = ? AND contractor_id = ?', [$tenderId, $contractorId]);
    if (!$b) {
        throw conflict('Contractor is not a recorded bidder for this tender');
    }
    if (!in_array($b['bid_status'], ['technically_qualified', 'financial_opened', 'awarded'], true)) {
        throw conflict('Bidder is not technically qualified');
    }
    $amt = (int) round((float) ($data['awarded_amount_minor'] ?? 0));
    $ceiling = (int) ($t['tech_sanction_amount_minor'] ?: $t['estimated_cost_minor']);
    if ($ceiling && $amt > $ceiling) {
        throw conflict('Award amount exceeds the approved/estimated amount');
    }
    $existing = DB::one('SELECT * FROM awards WHERE tender_id = ? ORDER BY id DESC LIMIT 1', [$tenderId]);
    if ($existing && in_array($existing['status'], ['approved', 'loa_issued', 'agreement_done', 'work_order_issued', 'completed'], true)) {
        throw conflict('An award already exists for this tender');
    }
    if ($existing && $existing['status'] === 'recommended') {
        DB::run('UPDATE awards SET contractor_id = ?, awarded_amount_minor = ?, remarks = ? WHERE id = ?', [$contractorId, $amt, $data['remarks'] ?? null, (int) $existing['id']]);
        $awardId = (int) $existing['id'];
    } else {
        $awardId = DB::insert(
            'INSERT INTO awards (uid, tender_id, contractor_id, bidder_id, awarded_amount_minor, ' . sql_ident('rank') . ', status, remarks, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [uid(), $tenderId, $contractorId, (int) $b['id'], $amt, $b['rank'] !== null ? (int) $b['rank'] : null, 'recommended', $data['remarks'] ?? null, (int) $actor['id']]
        );
    }
    audit_record('award.recommend', 'tender', $tenderId, $t['tender_number'], ['newValue' => ['contractor_id' => $contractorId, 'amount' => $amt]]);
    notify('award_recommended', 'Award recommended', $t['tender_number'] . ': award recommendation awaiting approval.', null, ['pradhan'], 'tender', $tenderId);
    return award_get($awardId);
}

function award_approve(int $awardId, array $data, array $actor): array
{
    $a = award_get($awardId);
    if ($a['status'] !== 'recommended') {
        throw conflict('Award is not in recommended state');
    }
    DB::run(
        "UPDATE awards SET status = 'approved', approval_authority = ?, approval_date = ? WHERE id = ?",
        [$data['authority'] ?? $actor['name'] ?? null, $data['date'] ?? today_iso(), $awardId]
    );
    DB::run("UPDATE tenders SET status = 'awarded' WHERE id = ?", [(int) $a['tender_id']]);
    DB::run("UPDATE tender_bidders SET bid_status = 'awarded' WHERE id = ?", [(int) $a['bidder_id']]);
    audit_record('award.approve', 'award', $awardId, $a['loa_number'] ?: "award#$awardId");
    return award_get($awardId);
}

function award_issue_loa(int $awardId, array $actor): array
{
    $a = award_get($awardId);
    if ($a['status'] !== 'approved') {
        throw conflict('Award must be approved before LOA issuance');
    }
    $fyId = (int) DB::val('SELECT fy_id FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $loaNumber = num_next_loa($fyId);
    $doc = document_generate_loa($awardId, $loaNumber, $actor);
    DB::run("UPDATE awards SET loa_number = ?, loa_date = ?, status = 'loa_issued' WHERE id = ?", [$loaNumber, today_iso(), $awardId]);
    audit_record('award.loa', 'award', $awardId, $loaNumber);
    return ['document' => $doc, 'award' => award_get($awardId)];
}

function award_create_agreement(int $awardId, array $data, array $actor): array
{
    $a = award_get($awardId);
    if (!in_array($a['status'], ['approved', 'loa_issued'], true)) {
        throw conflict('Award must be approved/LOA issued before agreement');
    }
    $fyId = (int) DB::val('SELECT fy_id FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $agreementNumber = num_next_agreement($fyId);
    $id = DB::insert(
        'INSERT INTO agreements (uid, tender_id, award_id, agreement_number, contractor_id, amount_minor, completion_period_days, conditions, security_deposit_minor, execution_date, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [
            uid(), (int) $a['tender_id'], $awardId, $agreementNumber, (int) $a['contractor_id'],
            (int) round((float) ($data['amount_minor'] ?? $a['awarded_amount_minor'])), $data['completion_period_days'] ?? null,
            $data['conditions'] ?? null, (int) round((float) ($data['security_deposit_minor'] ?? 0)),
            $data['execution_date'] ?? today_iso(), (int) $actor['id'],
        ]
    );
    DB::run("UPDATE awards SET agreement_id = ?, status = 'agreement_done' WHERE id = ?", [$id, $awardId]);
    audit_record('agreement.create', 'agreement', $id, $agreementNumber);
    return DB::one('SELECT * FROM agreements WHERE id = ?', [$id]);
}

function award_issue_work_order(int $awardId, array $data, array $actor): array
{
    $a = award_get($awardId);
    if ($a['status'] !== 'agreement_done') {
        throw conflict('Agreement must be executed before work order');
    }
    $t = DB::one('SELECT * FROM tenders WHERE id = ?', [(int) $a['tender_id']]);
    $ag = DB::one('SELECT * FROM agreements WHERE id = ?', [(int) $a['agreement_id']]);
    $woNumber = num_next_work_order((int) $t['fy_id']);
    $id = DB::insert(
        'INSERT INTO work_orders (uid, tender_id, award_id, agreement_id, project_id, contractor_id, work_order_number, amount_minor, start_date, completion_date, conditions, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            uid(), (int) $a['tender_id'], $awardId, (int) $a['agreement_id'], $t['project_id'], (int) $a['contractor_id'], $woNumber,
            $ag ? (int) $ag['amount_minor'] : (int) $a['awarded_amount_minor'],
            $data['start_date'] ?? today_iso(), $data['completion_date'] ?? null, $data['conditions'] ?? null, (int) $actor['id'],
        ]
    );
    DB::run("UPDATE awards SET work_order_id = ?, status = 'work_order_issued' WHERE id = ?", [$id, $awardId]);
    if ($t['project_id']) {
        DB::run(
            'UPDATE projects SET contractor_id = ?, work_order_id = ?, awarded_amount_minor = ?, status = ?,
               start_date = COALESCE(start_date, ?), planned_completion_date = COALESCE(planned_completion_date, ?) WHERE id = ?',
            [(int) $a['contractor_id'], $id, $ag ? (int) $ag['amount_minor'] : (int) $a['awarded_amount_minor'], 'awarded',
             $data['start_date'] ?? today_iso(), $data['completion_date'] ?? null, (int) $t['project_id']]
        );
    }
    audit_record('work_order.issue', 'work_order', $id, $woNumber);
    notify('work_order', 'Work order issued', $woNumber . ' issued for ' . ($t['work_name'] ?: $t['title']) . '.', null, ['technical_officer'], 'tender', (int) $a['tender_id']);
    return DB::one('SELECT * FROM work_orders WHERE id = ?', [$id]);
}

// ===========================================================================
// Execution / measurement / bills / payments / completion
// ===========================================================================
function execution_record_progress(int $projectId, array $data, array $actor): array
{
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    if (!$p) {
        throw not_found('Project not found');
    }
    $date = $data['progress_date'] ?? today_iso();
    $phys = (float) ($data['physical_progress'] ?? 0);
    $fin = (float) ($data['financial_progress'] ?? 0);
    $id = DB::insert(
        'INSERT INTO work_progress (uid, project_id, progress_date, physical_progress, financial_progress, milestone, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), $projectId, $date, $phys, $fin, $data['milestone'] ?? null, $data['notes'] ?? null, (int) $actor['id']]
    );
    DB::run('UPDATE projects SET physical_progress = ?, financial_progress = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$phys, $fin, $projectId]);
    if ($phys >= 100 && $p['status'] !== 'completed') {
        DB::run("UPDATE projects SET status = 'completed', actual_completion_date = ? WHERE id = ?", [$date, $projectId]);
    }
    audit_record('execution.progress', 'project', $projectId, $p['work_name'], ['newValue' => ['physicalProgress' => $phys, 'financialProgress' => $fin]]);
    return DB::one('SELECT * FROM work_progress WHERE id = ?', [$id]);
}

function execution_extension_request(int $projectId, array $data, array $actor): array
{
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    if (!$p) {
        throw not_found('Project not found');
    }
    $id = DB::insert(
        'INSERT INTO extension_requests (uid, project_id, requested_days, reason) VALUES (?,?,?,?)',
        [uid(), $projectId, (int) ($data['requested_days'] ?? 0), $data['reason'] ?? '']
    );
    audit_record('execution.extension_request', 'project', $projectId, $p['work_name'], ['reason' => $data['reason'] ?? null]);
    return DB::one('SELECT * FROM extension_requests WHERE id = ?', [$id]);
}

function measurement_create(int $projectId, array $data, array $actor): array
{
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    if (!$p) {
        throw not_found('Project not found');
    }
    $tender = DB::one('SELECT * FROM tenders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
    $number = num_next_measurement($projectId);
    $id = DB::insert(
        'INSERT INTO measurements (uid, project_id, tender_id, measurement_number, measurement_date, location, remarks, measured_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), $projectId, $tender ? (int) $tender['id'] : null, $number, $data['measurement_date'] ?? today_iso(), $data['location'] ?? null, $data['remarks'] ?? null, (int) $actor['id']]
    );
    audit_record('measurement.create', 'measurement', $id, $number);
    return DB::one('SELECT * FROM measurements WHERE id = ?', [$id]);
}

function measurement_add_item(int $measurementId, array $data, array $actor): array
{
    $m = DB::one('SELECT * FROM measurements WHERE id = ?', [$measurementId]);
    if (!$m) {
        throw not_found('Measurement not found');
    }
    if (!in_array($m['status'], ['draft', 'checked'], true)) {
        throw conflict('Measurement is locked');
    }
    $prev = (float) ($data['previous_quantity'] ?? 0);
    $cur = (float) ($data['current_quantity'] ?? 0);
    $cumulative = $prev + $cur;
    $overrun = 0;
    $boqItemId = int_or_null($data['boq_item_id'] ?? null);
    if ($boqItemId) {
        $boq = DB::one('SELECT * FROM boq_items WHERE id = ?', [$boqItemId]);
        if ($boq && $cumulative > (float) $boq['quantity'] + 1e-9) {
            $overrun = 1;
        }
    }
    $rate = (int) round((float) ($data['rate_minor'] ?? 0));
    $amount = qty_rate($cur, $rate);
    $id = DB::insert(
        'INSERT INTO measurement_items (uid, measurement_id, boq_item_id, item_no, description, unit, previous_quantity, current_quantity, cumulative_quantity, rate_minor, amount_minor, overrun_flag, remarks)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [uid(), $measurementId, $boqItemId, $data['item_no'] ?? null, $data['description'] ?? '', $data['unit'] ?? null, $prev, $cur, $cumulative, $rate, $amount, $overrun, $data['remarks'] ?? null]
    );
    audit_record('measurement.item_add', 'measurement', $measurementId, $m['measurement_number'] . ' item ' . ($data['item_no'] ?? ''), ['newValue' => ['currentQuantity' => $cur, 'overrun' => $overrun]]);
    return DB::one('SELECT * FROM measurement_items WHERE id = ?', [$id]);
}

function measurement_set_status(int $measurementId, string $status, array $actor): array
{
    $m = DB::one('SELECT * FROM measurements WHERE id = ?', [$measurementId]);
    if (!$m) {
        throw not_found('Measurement not found');
    }
    if (!in_array($status, ['checked', 'approved', 'final'], true)) {
        throw validation('Invalid measurement status');
    }
    $order = ['draft' => 0, 'checked' => 1, 'approved' => 2, 'final' => 3];
    if (($order[$status] ?? 0) < ($order[$m['status']] ?? 0)) {
        throw conflict('Cannot move measurement backwards');
    }
    if ($status === 'checked') {
        DB::run('UPDATE measurements SET status = ?, checked_by = ? WHERE id = ?', [$status, (int) $actor['id'], $measurementId]);
    } else {
        DB::run('UPDATE measurements SET status = ?, approved_by = ? WHERE id = ?', [$status, (int) $actor['id'], $measurementId]);
    }
    audit_record('measurement.' . $status, 'measurement', $measurementId, $m['measurement_number']);
    return DB::one('SELECT * FROM measurements WHERE id = ?', [$measurementId]);
}

function bill_create(int $projectId, array $data, array $actor): array
{
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    if (!$p) {
        throw not_found('Project not found');
    }
    $date = $data['bill_date'] ?? today_iso();
    $fy = fy_resolve_for_date($date);
    $wo = DB::one('SELECT * FROM work_orders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
    $number = num_next_bill_number((int) $fy['id']);
    $id = DB::insert(
        'INSERT INTO bills (uid, fy_id, project_id, tender_id, work_order_id, contractor_id, bill_number, bill_type, bill_date, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [uid(), (int) $fy['id'], $projectId, $wo ? (int) $wo['tender_id'] : null, $wo ? (int) $wo['id'] : null, $p['contractor_id'] !== null ? (int) $p['contractor_id'] : null, $number, $data['bill_type'] ?? 'running', $date, 'draft', (int) $actor['id']]
    );
    audit_record('bill.create', 'bill', $id, $number);
    return DB::one('SELECT * FROM bills WHERE id = ?', [$id]);
}

function bill_update(int $billId, array $data, array $actor): array
{
    $b = DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
    if (!$b) {
        throw not_found('Bill not found');
    }
    if ($b['status'] !== 'draft') {
        throw conflict('Only draft bills can be edited');
    }
    $c = fin_compute_bill($data);
    DB::run(
        'UPDATE bills SET gross_work_value_minor = ?, previous_certified_minor = ?, current_bill_minor = ?, cumulative_minor = ?,
           retention_minor = ?, tax_minor = ?, deductions_minor = ?, recoveries_minor = ?, net_payable_minor = ?, remarks = ?
         WHERE id = ?',
        [$c['grossMinor'], $c['previousCertifiedMinor'], $c['currentMinor'], $c['cumulativeMinor'], $c['retentionMinor'], $c['taxMinor'], $c['deductionsMinor'], (int) round((float) ($data['recoveriesMinor'] ?? 0)), $c['netPayableMinor'], $data['remarks'] ?? null, $billId]
    );
    audit_record('bill.update', 'bill', $billId, $b['bill_number']);
    return DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
}

function bill_submit(int $billId, array $actor): array
{
    $b = DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
    if (!$b) {
        throw not_found('Bill not found');
    }
    if ($b['status'] !== 'draft') {
        throw conflict('Bill is not in draft');
    }
    DB::run("UPDATE bills SET status = 'submitted' WHERE id = ?", [$billId]);
    workflow_init('bill', $billId, ['reset' => true]);
    audit_record('bill.submit', 'bill', $billId, $b['bill_number']);
    notify('bill_submitted', 'Bill submitted', $b['bill_number'] . ' awaiting technical check.', null, ['technical_officer'], 'bill', $billId);
    return DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
}

function bill_workflow_action(int $billId, string $action, ?string $remarks, array $actor): array
{
    $b = DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
    if (!$b) {
        throw not_found('Bill not found');
    }
    if (!in_array($b['status'], ['submitted', 'checked', 'certified', 'returned'], true)) {
        throw conflict('Bill is not in a workflow state');
    }
    $result = workflow_act('bill', $billId, $action, $remarks, $actor, function () use ($billId, $b, $actor) {
        DB::run("UPDATE bills SET status = 'approved', approved_by = ? WHERE id = ?", [(int) $actor['id'], $billId]);
        notify('bill_approved', 'Bill approved', $b['bill_number'] . ' approved for payment.', null, ['accounts_officer'], 'bill', $billId);
    });
    $map = ['reject' => 'rejected', 'return' => 'returned', 'clarification' => 'returned'];
    if (isset($map[$action])) {
        DB::run('UPDATE bills SET status = ? WHERE id = ?', [$map[$action], $billId]);
        audit_record('bill.' . $action, 'bill', $billId, $b['bill_number'], ['reason' => $remarks]);
        return DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
    }
    if ($action === 'approve') {
        $step = $result['step'];
        $statusByStep = ['Technical Check' => 'checked', 'Accounts Certification' => 'certified', 'Approval' => 'approved'];
        $s = $statusByStep[$step['step_name']] ?? null;
        if ($s) {
            $setter = ['checked' => null, 'certified' => 'certified_by', 'approved' => 'approved_by'][$s];
            if ($setter) {
                DB::run("UPDATE bills SET status = ?, $setter = ? WHERE id = ?", [$s, (int) $actor['id'], $billId]);
            } else {
                DB::run('UPDATE bills SET status = ? WHERE id = ?', [$s, $billId]);
            }
        }
    }
    audit_record('bill.' . $action, 'bill', $billId, $b['bill_number'], ['reason' => $remarks]);
    return DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
}

function payment_record(int $billId, array $data, array $actor): array
{
    $b = DB::one('SELECT * FROM bills WHERE id = ?', [$billId]);
    if (!$b) {
        throw not_found('Bill not found');
    }
    if (!in_array($b['status'], ['certified', 'approved'], true)) {
        throw conflict('Bill must be certified/approved before payment');
    }
    $alreadyPaid = (int) DB::val("SELECT COALESCE(SUM(net_amount_minor),0) FROM payments WHERE bill_id = ? AND status = 'recorded'", [$billId]);
    $gross = (int) round((float) ($data['gross_amount_minor'] ?? $data['net_amount_minor'] ?? 0));
    $ded = (int) round((float) ($data['deductions_minor'] ?? 0));
    $net = (int) round((float) ($data['net_amount_minor'] ?? 0)) ?: ($gross - $ded);
    if ($net <= 0) {
        throw validation('Payment amount must be positive');
    }
    $certifiedNet = (int) $b['net_payable_minor'];
    if ($certifiedNet > 0 && $alreadyPaid + $net > $certifiedNet) {
        throw conflict('Payment would exceed the certified bill amount');
    }
    $date = $data['payment_date'] ?? today_iso();
    $fy = fy_resolve_for_date($date);
    $count = (int) DB::val('SELECT COUNT(*) FROM payments WHERE fy_id = ?', [(int) $fy['id']]) + 1;
    $voucher = 'PVR/' . $fy['label'] . '/' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    $id = DB::insert(
        'INSERT INTO payments (uid, fy_id, bill_id, project_id, contractor_id, voucher_no, payment_date, gross_amount_minor, deductions_minor, net_amount_minor, payment_method, transaction_reference, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            uid(), (int) $fy['id'], $billId, $b['project_id'], $b['contractor_id'], $voucher, $date,
            $gross, $ded, $net, $data['payment_method'] ?? null, $data['transaction_reference'] ?? null, 'recorded', (int) $actor['id'],
        ]
    );
    $totalPaid = $alreadyPaid + $net;
    DB::run('UPDATE bills SET status = ? WHERE id = ?', [$totalPaid >= $certifiedNet ? 'paid' : 'partially_paid', $billId]);
    audit_record('payment.record', 'payment', $id, $voucher, ['newValue' => ['net' => $net, 'voucher' => $voucher], 'reason' => 'Payment recorded (no bank integration — see notes)']);
    return DB::one('SELECT * FROM payments WHERE id = ?', [$id]);
}

function completion_get_or_create(int $projectId): array
{
    $existing = DB::one('SELECT * FROM completions WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
    if ($existing) {
        return $existing;
    }
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    if (!$p) {
        throw not_found('Project not found');
    }
    $tender = DB::one('SELECT * FROM tenders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
    $id = DB::insert('INSERT INTO completions (uid, project_id, tender_id) VALUES (?,?,?)', [uid(), $projectId, $tender ? (int) $tender['id'] : null]);
    return DB::one('SELECT * FROM completions WHERE id = ?', [$id]);
}

function completion_advance(int $projectId, array $data, array $actor): array
{
    $comp = completion_get_or_create($projectId);
    $p = DB::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
    $status = $data['status'] ?? '';
    $map = [
        'inspection_done' => 'inspection_date',
        'final_measurement_done' => null,
        'final_bill_done' => null,
        'final_payment_done' => null,
        'security_released' => 'security_release_date',
        'closed' => 'handover_date',
    ];
    if (!array_key_exists($status, $map)) {
        throw validation('Invalid completion status');
    }
    $date = $data['date'] ?? today_iso();
    $sets = ['status = ?'];
    $params = [$status];
    if ($map[$status] && ($data['date'] ?? null)) {
        $sets[] = $map[$status] . ' = ?';
        $params[] = $data['date'];
    }
    if (!empty($data['final_measurement_id'])) { $sets[] = 'final_measurement_id = ?'; $params[] = (int) $data['final_measurement_id']; }
    if (!empty($data['final_bill_id'])) { $sets[] = 'final_bill_id = ?'; $params[] = (int) $data['final_bill_id']; }
    if (!empty($data['final_payment_id'])) { $sets[] = 'final_payment_id = ?'; $params[] = (int) $data['final_payment_id']; }
    $params[] = (int) $comp['id'];
    DB::run('UPDATE completions SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    if ($status === 'closed') {
        DB::run("UPDATE projects SET status = 'closed', actual_completion_date = ? WHERE id = ?", [$date, $projectId]);
    }
    audit_record('completion.' . $status, 'project', $projectId, $p['work_name']);
    return DB::one('SELECT * FROM completions WHERE id = ?', [(int) $comp['id']]);
}

// ===========================================================================
// Reports
// ===========================================================================
function report_dashboard(?int $fyId): array
{
    $F = $fyId ? " AND t.fy_id = $fyId" : '';
    $Fp = $fyId ? " AND p.fy_id = $fyId" : '';
    $Fb = $fyId ? " AND b.fy_id = $fyId" : '';
    $Fpay = $fyId ? " AND pay.fy_id = $fyId" : '';

    $tenderCounts = DB::one(
        "SELECT COUNT(*) total,
          SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) draft,
          SUM(CASE WHEN status IN ('approved','nit_generated','published') THEN 1 ELSE 0 END) published,
          SUM(CASE WHEN status='bidding' THEN 1 ELSE 0 END) bidding,
          SUM(CASE WHEN status IN ('technical_evaluation','financial_evaluation') THEN 1 ELSE 0 END) evaluation,
          SUM(CASE WHEN status='awarded' THEN 1 ELSE 0 END) awarded,
          SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled,
          SUM(CASE WHEN status='retendered' THEN 1 ELSE 0 END) retendered,
          SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) closed,
          COALESCE(SUM(tender_value_minor),0) tender_value,
          COALESCE(SUM(estimated_cost_minor),0) estimated_value
         FROM tenders t WHERE t.deleted_at IS NULL $F"
    );
    $projects = DB::one(
        "SELECT COUNT(*) total,
          SUM(CASE WHEN status IN ('in_progress','awarded','tendered') THEN 1 ELSE 0 END) ongoing,
          SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
          SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) closed
         FROM projects p WHERE p.deleted_at IS NULL $Fp"
    );
    $bills = DB::one("SELECT COUNT(*) total, SUM(CASE WHEN status IN ('submitted','checked','certified','approved') THEN 1 ELSE 0 END) pending FROM bills b WHERE 1=1 $Fb");
    $paid = (int) DB::val("SELECT COALESCE(SUM(net_amount_minor),0) FROM payments pay WHERE pay.status='recorded' $Fpay");
    $awarded = (int) DB::val("SELECT COALESCE(SUM(a.awarded_amount_minor),0) FROM awards a JOIN tenders t ON t.id=a.tender_id WHERE a.status != 'cancelled' $F");
    $complianceIssues = (int) DB::val("SELECT COUNT(*) FROM tenders t WHERE t.deleted_at IS NULL $F AND t.compliance_status IN ('blocking','warning')");
    $pendingApprovals = (int) DB::val(
        "SELECT COUNT(*) FROM approval_steps aps JOIN tenders t ON t.id = aps.entity_id AND aps.entity_type='tender' WHERE aps.status IN ('pending','in_progress') $F"
    );

    return [
        'tenders' => $tenderCounts,
        'projects' => $projects,
        'bills' => $bills,
        'payments' => ['paidMinor' => $paid],
        'awards' => ['awardedMinor' => $awarded],
        'complianceIssues' => $complianceIssues,
        'pendingApprovals' => $pendingApprovals,
        'statusPipeline' => DB::all("SELECT status, COUNT(*) c FROM tenders t WHERE t.deleted_at IS NULL $F GROUP BY status ORDER BY c DESC"),
        'recentActivity' => DB::all('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 12'),
    ];
}

function report_reconciliation(?int $fyId): array
{
    $F = $fyId ? " AND t.fy_id = $fyId" : '';
    return DB::all(
        "SELECT t.id, t.tender_number, t.title, f.label fy_label,
                t.estimated_cost_minor, t.tender_value_minor,
                COALESCE(a.awarded,0) awarded_minor,
                COALESCE(b.certified,0) certified_minor,
                COALESCE(pay.paid,0) paid_minor
           FROM tenders t
           LEFT JOIN financial_years f ON f.id=t.fy_id
           LEFT JOIN (SELECT tender_id, SUM(awarded_amount_minor) awarded FROM awards WHERE status!='cancelled' GROUP BY tender_id) a ON a.tender_id=t.id
           LEFT JOIN (SELECT tender_id, SUM(current_bill_minor) certified FROM bills WHERE status IN ('certified','approved','paid','partially_paid') GROUP BY tender_id) b ON b.tender_id=t.id
           LEFT JOIN (SELECT b2.tender_id, SUM(pay2.net_amount_minor) paid FROM payments pay2 JOIN bills b2 ON b2.id=pay2.bill_id WHERE pay2.status='recorded' GROUP BY b2.tender_id) pay ON pay.tender_id=t.id
          WHERE t.deleted_at IS NULL $F ORDER BY t.id DESC LIMIT 200"
    );
}
