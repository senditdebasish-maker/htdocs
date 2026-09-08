<?php
/**
 * Front controller for the GP Procurement & Work Management Portal.
 * All authorization is enforced server-side in the service/guard layer; the
 * HTML layer only reflects what the server permits.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/schema.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/audit.php';
require_once __DIR__ . '/app/notifications.php';
require_once __DIR__ . '/app/workflow.php';
require_once __DIR__ . '/app/compliance.php';
require_once __DIR__ . '/app/services.php';
require_once __DIR__ . '/app/documents.php';
require_once __DIR__ . '/app/backup.php';
require_once __DIR__ . '/app/seed.php';
require_once __DIR__ . '/app/view.php';
require_once __DIR__ . '/app/installer.php';

// Self-healing database bootstrap. DB::connect() creates the database if it
// does not exist yet, so a fresh XAMPP box needs no manual phpMyAdmin step.
try {
    DB::pdo();
} catch (Throwable $e) {
    http_response_code(503);
    render_page('Database unavailable', '<div class="alert alert-danger"><h1>Database unavailable</h1>'
        . '<p>' . e($e->getMessage()) . '</p>'
        . '<p class="muted">Check that MySQL is running in XAMPP and that the credentials in <code>config.php</code> are correct.</p></div>', '', false);
    exit;
}
start_app_session();

// ---------------------------------------------------------------------------
// Routing helpers
// ---------------------------------------------------------------------------
$reqMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$reqBase = base_path();
$reqPath = substr($reqUri, strlen($reqBase));
if ($reqPath === '' || $reqPath === false) {
    $reqPath = '/';
}
if ($reqPath[0] !== '/') {
    $reqPath = '/' . $reqPath;
}
$reqPath = '/' . trim($reqPath, '/');
// Support PATH_INFO style URLs (/index.php/tenders) in addition to rewritten ones.
if (strpos($reqPath, '/index.php') === 0) {
    $reqPath = substr($reqPath, strlen('/index.php'));
    if ($reqPath === '' || $reqPath === '/') {
        $reqPath = '/';
    }
}
$segments = $reqPath === '/' ? [] : explode('/', trim($reqPath, '/'));

// ---------------------------------------------------------------------------
// Global error handler. Converts AppError into a proper HTTP response
// (redirect to login for 401, rendered page for 403/404/409/422/400) and
// unexpected errors into a logged 500 — instead of a PHP fatal error.
// ---------------------------------------------------------------------------
set_exception_handler(function (Throwable $e) use ($reqPath): void {
    try {
        if ($e instanceof AppError) {
            if ($e->status === 401) {
                // Not signed in: remember where they were headed, then log in.
                if ($reqPath !== '/login') {
                    $_SESSION['login_redirect'] = $reqPath;
                }
                header('Location: ' . app_url('/login'));
                exit;
            }
            $titles = [400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Validation Error', 503 => 'Service Unavailable'];
            $title = $titles[$e->status] ?? 'Error';
            http_response_code($e->status);
            render_page($title,
                '<div class="card"><h1>' . e($title) . '</h1>'
                . '<p class="muted">' . e($e->getMessage()) . '</p>'
                . '<div class="actions"><a class="btn" href="' . e(app_url('/')) . '">Back to home</a></div></div>',
                '', (bool) current_user());
            exit;
        }
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        render_page('Server Error',
            '<div class="card"><h1>Server Error</h1>'
            . '<p class="muted">Something went wrong. The error has been logged for the administrator.</p>'
            . '<div class="actions"><a class="btn" href="' . e(app_url('/')) . '">Back to home</a></div></div>',
            '', (bool) current_user());
        exit;
    } catch (Throwable $fallback) {
        // Never rethrow from the handler itself.
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Server Error</title></head>'
            . '<body style="font-family:sans-serif;padding:2rem"><h1>Server Error</h1>'
            . '<p>An unexpected error occurred.</p></body></html>';
        exit;
    }
});

function seg(int $i): ?string
{
    global $segments;
    return $segments[$i] ?? null;
}

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function money_in(?string $key): ?int
{
    $v = post($key);
    if ($v === null || $v === '') {
        return null;
    }
    return to_minor((string) $v);
}

/** Guard: require auth + permission; returns the user. */
function guard(string $perm): array
{
    return require_permission($perm);
}

/** Run an action handler, converting AppError into a flash + redirect. */
function act(callable $fn, string $fallback = '/'): void
{
    try {
        $fn();
    } catch (AppError $e) {
        flash_set($e->getMessage(), 'danger');
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $safe = '';
        if (is_string($ref) && $ref !== '') {
            $parts = parse_url($ref);
            if (is_array($parts)) {
                $currentHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
                $refHost = '';
                if (isset($parts['host'])) {
                    $refHost = strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
                }
                if ($refHost === '' || $refHost === $currentHost) {
                    $path = (string) ($parts['path'] ?? '/');
                    $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
                    $bp = base_path();
                    if ($bp === '' || $path === $bp || str_starts_with($path, $bp . '/')) {
                        $safe = ($bp !== '' ? substr($path, strlen($bp)) : $path) . $query;
                    }
                }
            }
        }
        header('Location: ' . app_url($safe !== '' ? $safe : $fallback));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Install guard + silent self-repair + installer route
// ---------------------------------------------------------------------------
$installed = false;
try {
    $installed = schema_installed();
    if ($installed) {
        if (!schema_uptodate()) {
            // Schema drift (code changed since last run): repair in place.
            schema_heal();
        }
    } elseif (db_tables()) {
        // Partially-installed database (e.g. an interrupted import that hit a
        // foreign-key error): repair in place, never dropping existing data.
        schema_heal();
        $installed = schema_installed();
    }
} catch (Throwable $e) {
    $installed = false;
}
if (!$installed && $reqPath !== '/install') {
    header('Location: ' . app_url('/install'));
    exit;
}
if ($reqPath === '/install') {
    install_page();
    exit;
}

// ===========================================================================
// AUTH PAGES
// ===========================================================================
if ($reqPath === '/login') {
    if ($reqMethod === 'GET') {
        render_page('Login', login_form_html(), '', false);
        exit;
    }
    if ($reqMethod === 'POST') {
        act(function () {
            csrf_check();
            $u = login_user((string) post('email'), (string) post('password'));
            flash_set('Welcome, ' . $u['user']['name'] . '.', 'success');
            // Resume the page the user was headed to before signing in.
            $dest = $_SESSION['login_redirect'] ?? null;
            unset($_SESSION['login_redirect']);
            if (is_string($dest) && $dest !== '' && $dest[0] === '/' && strpos($dest, '//') !== 0) {
                header('Location: ' . app_url($dest));
            } else {
                header('Location: ' . app_url('/'));
            }
            exit;
        }, '/login');
        exit;
    }
}

if ($reqPath === '/logout') {
    audit_record('auth.logout', 'user', current_user() ? (int) current_user()['id'] : null, current_user() ? current_user()['email'] : null);
    logout_user();
    header('Location: ' . app_url('/login'));
    exit;
}

// ===========================================================================
// PUBLIC PORTAL (read-only)
// ===========================================================================
if ($reqPath === '/public' || $reqPath === '/public/tenders') {
    render_page('Public Portal', public_list_html(), 'public', (bool) current_user());
    exit;
}

if (seg(0) === 'public' && seg(1) === 'tenders' && seg(2) !== null) {
    render_page('Public Tender', public_tender_html((int) seg(2)), 'public', (bool) current_user());
    exit;
}

// Public access to published NIT PDFs only. All other document downloads are
// handled below after authentication and RBAC/scope checks.
if (seg(0) === 'documents' && is_numeric(seg(1) ?? '') && !current_user()) {
    $doc = DB::one('SELECT * FROM documents WHERE id = ?', [(int) seg(1)]);
    if (!$doc) { throw not_found('Document not found'); }
    $publicNit = $doc['category'] === 'nit' && $doc['entity_type'] === 'tender' && $doc['entity_id']
        && DB::one("SELECT id FROM tenders WHERE id = ? AND deleted_at IS NULL AND status IN ('published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','closed')", [(int) $doc['entity_id']]);
    if (!$publicNit) { require_auth(); }
    $file = document_file_path($doc);
    if (!$file) { throw not_found('Document file not found'); }
    audit_record('document.public_download', 'document', (int) $doc['id'], $doc['original_name']);
    $mime = $doc['mime_type'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $doc['original_name']) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ---------------------------------------------------------------------------
// Everything below requires authentication.
// ---------------------------------------------------------------------------
$user = require_auth();

// ===========================================================================
// DASHBOARD
// ===========================================================================
if ($reqPath === '/') {
    guard('report.view');
    render_page('Dashboard', dashboard_html($user), 'dashboard');
    exit;
}


// ===========================================================================
// PANCHAYAT / PLANNING MASTER DATA
// ===========================================================================
if ($reqPath === '/panchayat') {
    if ($reqMethod === 'POST') {
        act(function () use ($user) {
            guard('panchayat.manage'); csrf_check();
            $pid = (int) post('id', 0);
            if ($pid <= 0) { throw validation('Panchayat id missing.'); }
            $row = DB::one('SELECT * FROM panchayats WHERE id = ?', [$pid]);
            if (!$row) { throw not_found('Panchayat not found'); }
            scope_assert_row($row, $user, 'panchayat');
            $allowed = ['state','district','block','gram_panchayat','gp_code','office_address','pin','phone','email','pradhan','upa_pradhan','panchayat_secretary','technical_officer','accounts_officer','letterhead_html','document_header_html','document_footer_html'];
            $sets = []; $params = [];
            foreach ($allowed as $f) {
                if (post($f) !== null) { $sets[] = $f . ' = ?'; $params[] = post($f); }
            }
            if ($sets) {
                $params[] = $pid;
                DB::run('UPDATE panchayats SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?', $params);
            }
            audit_record('panchayat.update', 'panchayat', $pid, post('gram_panchayat'));
            flash_set('Panchayat settings saved.', 'success');
            header('Location: ' . app_url('/panchayat')); exit;
        }, '/panchayat');
        exit;
    }
    guard('panchayat.view');
    render_page('Panchayat Settings', panchayat_settings_html($user), 'panchayat');
    exit;
}

if ($reqPath === '/schemes') {
    guard('scheme.view');
    render_page('Schemes', schemes_html($user), 'schemes');
    exit;
}
if ($reqPath === '/schemes/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('scheme.manage'); csrf_check();
        $name = trim((string) post('name'));
        if ($name === '') { throw validation('Scheme name is required.'); }
        $code = trim((string) post('code')) ?: null;
        if ($code && DB::one('SELECT id FROM schemes WHERE code = ?', [$code])) { throw conflict('Scheme code already exists.'); }
        $id = DB::insert('INSERT INTO schemes (uid, name, code, sponsoring_authority, description, is_active) VALUES (?,?,?,?,?,1)',
            [uid(), $name, $code, post('sponsoring_authority'), post('description')]);
        audit_record('scheme.create', 'scheme', $id, $name);
        flash_set('Scheme created.', 'success');
        header('Location: ' . app_url('/schemes')); exit;
    }, '/schemes');
    exit;
}

if ($reqPath === '/funds') {
    guard('scheme.view');
    render_page('Funds', funds_html($user), 'funds');
    exit;
}
if ($reqPath === '/funds/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('scheme.manage'); csrf_check();
        $name = trim((string) post('name'));
        if ($name === '') { throw validation('Fund name is required.'); }
        $code = trim((string) post('code')) ?: null;
        if ($code && DB::one('SELECT id FROM funds WHERE code = ?', [$code])) { throw conflict('Fund code already exists.'); }
        $id = DB::insert('INSERT INTO funds (uid, name, code, funding_source, head_of_account, is_active) VALUES (?,?,?,?,?,1)',
            [uid(), $name, $code, post('funding_source'), post('head_of_account')]);
        audit_record('fund.create', 'fund', $id, $name);
        flash_set('Fund created.', 'success');
        header('Location: ' . app_url('/funds')); exit;
    }, '/funds');
    exit;
}

if ($reqPath === '/budgets') {
    guard('scheme.view');
    render_page('Budgets', budgets_html($user), 'budgets');
    exit;
}
if ($reqPath === '/budgets/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('scheme.manage'); csrf_check();
        $fy = DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) post('fy_id')]);
        if (!$fy) { throw validation('Select a valid financial year.'); }
        if ($fy['status'] === 'closed') { throw conflict('Cannot add budget allocation to a closed financial year.'); }
        $amount = money_in('sanctioned_amount');
        if ($amount === null || $amount < 0) { throw validation('Budget amount must be zero or positive.'); }
        $id = DB::insert('INSERT INTO budget_allocations (uid, panchayat_id, fy_id, scheme_id, fund_id, sanctioned_amount_minor) VALUES (?,?,?,?,?,?)',
            [uid(), actor_panchayat_id($user), (int) $fy['id'], int_or_null(post('scheme_id')), int_or_null(post('fund_id')), $amount]);
        audit_record('budget.create', 'budget', $id, 'Budget ' . $fy['label']);
        flash_set('Budget allocation recorded.', 'success');
        header('Location: ' . app_url('/budgets')); exit;
    }, '/budgets');
    exit;
}

if ($reqPath === '/procurement-plans') {
    guard('plan.view');
    render_page('Procurement Plans', procurement_plans_html($user), 'plans');
    exit;
}
if ($reqPath === '/procurement-plans/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('plan.manage'); csrf_check();
        $fy = DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) post('fy_id')]);
        if (!$fy) { throw validation('Select a valid financial year.'); }
        if ($fy['status'] === 'closed') { throw conflict('Cannot create a procurement plan in a closed financial year.'); }
        $work = trim((string) post('work_name'));
        if ($work === '') { throw validation('Work name is required.'); }
        $amount = money_in('estimated_amount');
        if ($amount === null || $amount <= 0) { throw validation('Estimated amount must be positive.'); }
        $id = DB::insert('INSERT INTO procurement_plans (uid, panchayat_id, fy_id, scheme_id, fund_id, work_name, estimated_amount_minor, procurement_method, expected_date, responsible_officer, budget_provision, remarks, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [uid(), actor_panchayat_id($user), (int) $fy['id'], int_or_null(post('scheme_id')), int_or_null(post('fund_id')), $work, $amount, post('procurement_method'), date_or_null(post('expected_date')), post('responsible_officer'), post('budget_provision'), post('remarks'), 'planned', (int) $user['id']]);
        audit_record('plan.create', 'procurement_plan', $id, $work);
        flash_set('Procurement plan created.', 'success');
        header('Location: ' . app_url('/procurement-plans')); exit;
    }, '/procurement-plans');
    exit;
}
if (seg(0) === 'procurement-plans' && is_numeric(seg(1) ?? '') && seg(2) === 'project' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('plan.manage'); csrf_check();
        $plan = DB::one('SELECT * FROM procurement_plans WHERE id = ?', [(int) seg(1)]);
        if (!$plan) { throw not_found('Procurement plan not found'); }
        scope_assert_row($plan, $user, 'procurement plan');
        if (!empty($plan['project_id'])) { header('Location: ' . app_url('/projects/' . $plan['project_id'])); exit; }
        $projectId = DB::insert('INSERT INTO projects (uid, panchayat_id, fy_id, scheme_id, fund_id, work_name, description, estimate_amount_minor, sanctioned_amount_minor, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [uid(), $plan['panchayat_id'] ?? actor_panchayat_id($user), (int) $plan['fy_id'], $plan['scheme_id'], $plan['fund_id'], $plan['work_name'], 'Created from procurement plan #' . $plan['id'], (int) $plan['estimated_amount_minor'], (int) $plan['estimated_amount_minor'], 'planned', (int) $user['id']]);
        DB::run("UPDATE procurement_plans SET project_id = ?, status = 'initiated' WHERE id = ?", [$projectId, (int) $plan['id']]);
        audit_record('plan.convert_project', 'procurement_plan', (int) $plan['id'], $plan['work_name'], ['newValue' => ['project_id' => $projectId]]);
        flash_set('Project created from procurement plan.', 'success');
        header('Location: ' . app_url('/projects/' . $projectId)); exit;
    }, '/procurement-plans');
    exit;
}
if (seg(0) === 'procurement-plans' && is_numeric(seg(1) ?? '') && seg(2) === 'tender' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('plan.manage'); csrf_check();
        $plan = DB::one('SELECT * FROM procurement_plans WHERE id = ?', [(int) seg(1)]);
        if (!$plan) { throw not_found('Procurement plan not found'); }
        scope_assert_row($plan, $user, 'procurement plan');
        $projectId = (int) ($plan['project_id'] ?? 0);
        if ($projectId <= 0) {
            $projectId = DB::insert('INSERT INTO projects (uid, panchayat_id, fy_id, scheme_id, fund_id, work_name, description, estimate_amount_minor, sanctioned_amount_minor, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [uid(), $plan['panchayat_id'] ?? actor_panchayat_id($user), (int) $plan['fy_id'], $plan['scheme_id'], $plan['fund_id'], $plan['work_name'], 'Created from procurement plan #' . $plan['id'], (int) $plan['estimated_amount_minor'], (int) $plan['estimated_amount_minor'], 'planned', (int) $user['id']]);
            DB::run("UPDATE procurement_plans SET project_id = ?, status = 'initiated' WHERE id = ?", [$projectId, (int) $plan['id']]);
        }
        $t = tender_create(['fy_id' => (int) $plan['fy_id'], 'project_id' => $projectId, 'scheme_id' => $plan['scheme_id'], 'fund_id' => $plan['fund_id'], 'tender_type' => 'open', 'procurement_category' => 'works', 'procurement_method' => $plan['procurement_method'], 'title' => $plan['work_name'], 'work_name' => $plan['work_name']], $user);
        DB::run("UPDATE procurement_plans SET status = 'tender_created' WHERE id = ?", [(int) $plan['id']]);
        audit_record('plan.convert_tender', 'procurement_plan', (int) $plan['id'], $plan['work_name'], ['newValue' => ['tender_id' => $t['id']]]);
        flash_set('Tender created from procurement plan.', 'success');
        header('Location: ' . app_url('/tenders/' . $t['id'])); exit;
    }, '/procurement-plans');
    exit;
}

// ===========================================================================
// TENDERS
// ===========================================================================
if ($reqPath === '/tenders') {
    guard('tender.view');
    render_page('Tenders', tenders_list_html($user), 'tenders');
    exit;
}

if ($reqPath === '/tenders/new') {
    guard('tender.manage');
    render_page('New Tender', tender_form_html(null, $user), 'tenders');
    exit;
}

if ($reqPath === '/tenders/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.manage');
        csrf_check();
        $t = tender_create([
            'fy_id' => (int) post('fy_id'),
            'project_id' => post('project_id'),
            'scheme_id' => post('scheme_id'),
            'fund_id' => post('fund_id'),
            'ruleset_id' => post('ruleset_id'),
            'tender_type' => post('tender_type'),
            'procurement_category' => post('procurement_category'),
            'procurement_method' => post('procurement_method'),
            'title' => post('title'),
            'work_name' => post('work_name'),
            'description' => post('description'),
            'location' => post('location'),
        ], $user);
        flash_set('Tender ' . $t['tender_number'] . ' created.', 'success');
        header('Location: ' . app_url('/tenders/' . $t['id']));
        exit;
    }, '/tenders/new');
    exit;
}

if (seg(0) === 'tenders' && seg(1) !== null && is_numeric(seg(1)) && seg(2) === null) {
    $tenderId = (int) seg(1);
    render_page('Tender', tender_detail_html($tenderId, $user), 'tenders');
    exit;
}

if (seg(0) === 'tenders' && seg(1) !== null && is_numeric(seg(1)) && seg(2) === 'update' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.manage');
        csrf_check();
        $tid = (int) seg(1);
        $fields = [];
        foreach (['tender_type','procurement_category','procurement_method','title','work_name','description','location','admin_approval_no','admin_approval_date','admin_approval_authority','tech_sanction_no','tech_sanction_date','tech_sanction_authority','emd_exemption_notes','budget_provision','head_of_account','technical_specification','eligibility_notes','general_conditions','special_conditions','payment_conditions','completion_conditions','extension_conditions','penalty_provisions','defect_liability'] as $f) {
            if (post($f) !== null) $fields[$f] = post($f);
        }
        foreach (['admin_approval_amount_minor','tech_sanction_amount_minor','estimated_cost_minor','tender_value_minor','emd_minor','tender_fee_minor','security_deposit_minor'] as $f) {
            if (post($f) !== null && post($f) !== '') $fields[$f] = to_minor((string) post($f));
        }
        if (post('security_deposit_pct') !== null && post('security_deposit_pct') !== '') $fields['security_deposit_pct'] = (float) post('security_deposit_pct');
        if (post('completion_period_days') !== null && post('completion_period_days') !== '') $fields['completion_period_days'] = (int) post('completion_period_days');
        if (post('bid_validity_days') !== null && post('bid_validity_days') !== '') $fields['bid_validity_days'] = (int) post('bid_validity_days');
        foreach (['publication_date','bid_start_date','bid_close_date','technical_open_date','financial_open_date'] as $f) {
            $fields[$f] = post($f) !== null ? post($f) : null;
        }
        if (post('project_id') !== null && post('project_id') !== '') $fields['project_id'] = (int) post('project_id');
        if (post('scheme_id') !== null && post('scheme_id') !== '') $fields['scheme_id'] = (int) post('scheme_id');
        if (post('fund_id') !== null && post('fund_id') !== '') $fields['fund_id'] = (int) post('fund_id');
        if (post('ruleset_id') !== null && post('ruleset_id') !== '') $fields['ruleset_id'] = (int) post('ruleset_id');
        tender_update($tid, $fields, $user);
        flash_set('Tender updated.', 'success');
        header('Location: ' . app_url('/tenders/' . $tid));
        exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'boq' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('boq.manage');
        csrf_check();
        $tid = (int) seg(1);
        $tender = tender_get($tid);
        if ($tender['status'] !== 'draft') { throw conflict('BOQ can be edited only while the tender is draft.'); }
        if (trim((string) post('description')) === '') { throw validation('BOQ description is required.'); }
        $qty = (float) post('quantity', 0);
        $rate = to_minor((string) post('estimated_rate'));
        if ($qty <= 0 || $rate <= 0) { throw validation('BOQ quantity and estimated rate must be positive.'); }
        DB::insert(
            'INSERT INTO boq_items (uid, panchayat_id, tender_id, item_no, group_name, description, specification, unit, quantity, estimated_rate_minor, tax_pct, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [uid(), $tender['panchayat_id'] ?? actor_panchayat_id($user), $tid, post('item_no') ?: (string) ((int) DB::val('SELECT COUNT(*) FROM boq_items WHERE tender_id = ?', [$tid]) + 1), post('group_name'), post('description'), post('specification'), post('unit'), $qty, $rate, (float) post('tax_pct', 0), (int) post('sort_order', 0)]
        );
        flash_set('BOQ item added.', 'success');
        header('Location: ' . app_url('/tenders/' . $tid));
        exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'submit' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.manage'); csrf_check();
        $t = tender_submit((int) seg(1), $user);
        flash_set($t['tender_number'] . ' submitted for approval.', 'success');
        header('Location: ' . app_url('/tenders/' . $t['id'])); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'compliance' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('rules.view'); csrf_check();
        tender_run_compliance((int) seg(1), $user);
        flash_set('Compliance check completed.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'workflow' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.approve'); csrf_check();
        tender_workflow_action((int) seg(1), (string) post('action', 'approve'), post('remarks'), $user);
        flash_set('Workflow action recorded.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'nit' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('nit.manage'); csrf_check();
        $r = tender_generate_nit((int) seg(1), $user);
        flash_set('NIT generated. <a href="' . app_url('/documents/' . $r['document']['id']) . '">View NIT</a>', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}


if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'tender-document' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('nit.manage'); csrf_check();
        $doc = document_generate_tender_document((int) seg(1), $user);
        flash_set('Tender document generated. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'boq-pdf' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('boq.manage'); csrf_check();
        $doc = document_generate_boq((int) seg(1), $user);
        flash_set('BOQ PDF generated. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'technical-evaluation-pdf' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('technical_eval.manage'); csrf_check();
        $doc = document_generate_technical_evaluation((int) seg(1), $user);
        flash_set('Technical evaluation PDF generated. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'comparative-pdf' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('comparative.view'); csrf_check();
        $doc = document_generate_comparative((int) seg(1), $user);
        flash_set('Comparative statement PDF generated. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'complete-file' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('document.download'); csrf_check();
        $doc = document_generate_complete_tender_file((int) seg(1), $user);
        flash_set('Complete tender file index generated. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'publish' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.publish'); csrf_check();
        tender_publish((int) seg(1), [
            'publication_date' => post('publication_date'),
            'official_portal' => post('official_portal'),
            'external_tender_id' => post('external_tender_id'),
            'publication_status' => post('publication_status'),
            'sync_method' => 'manual',
        ], $user);
        flash_set('Tender published.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'start-bidding' && $reqMethod === 'POST') {
    act(function () use ($user) { guard('tender.publish'); csrf_check(); tender_start_bidding((int) seg(1), $user); flash_set('Bidding started.', 'success'); header('Location: ' . app_url('/tenders/' . seg(1))); exit; }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'close-bids' && $reqMethod === 'POST') {
    act(function () use ($user) { guard('tender.publish'); csrf_check(); tender_close_bids((int) seg(1), $user); flash_set('Bids closed.', 'success'); header('Location: ' . app_url('/tenders/' . seg(1))); exit; }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'cancel' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.cancel'); csrf_check();
        tender_cancel((int) seg(1), ['reason' => post('reason'), 'authority' => post('authority'), 'cancel_date' => post('cancel_date')], $user);
        flash_set('Tender cancelled.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'corrigendum' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.corrigendum'); csrf_check();
        tender_corrigendum((int) seg(1), ['reason' => post('reason'), 'changes' => [post('changes')], 'new_dates' => json_load(post('new_dates_json', ''), [])], $user);
        flash_set('Corrigendum issued.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'retender' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('tender.retender'); csrf_check();
        $t = tender_retender((int) seg(1), ['reason' => post('reason')], $user);
        flash_set('Re-tender created: ' . $t['tender_number'], 'success');
        header('Location: ' . app_url('/tenders/' . $t['id'])); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'comparative') {
    guard('comparative.view');
    render_page('Comparative Statement', comparative_html((int) seg(1)), 'tenders');
    exit;
}

// ===========================================================================
// BIDS & EVALUATION
// ===========================================================================
if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'bidders' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('bid.manage'); csrf_check();
        bidder_add((int) seg(1), ['contractor_id' => (int) post('contractor_id'), 'emd_paid_minor' => money_in('emd_paid_minor'), 'emd_details' => post('emd_details')], $user);
        flash_set('Bidder recorded.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'technical-open' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('technical_open.manage'); csrf_check();
        bidder_technical_open((int) seg(1), ['opening_date' => post('opening_date'), 'observations' => post('observations')], $user);
        flash_set('Technical opening recorded.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'criteria' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('technical_eval.manage'); csrf_check();
        criteria_add((int) seg(1), ['code' => post('code'), 'criterion' => post('criterion'), 'requirement' => post('requirement'), 'is_required' => post('is_required')]);
        flash_set('Criterion added.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'evaluation' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('technical_eval.manage'); csrf_check();
        evaluation_set((int) seg(1), (int) post('bidder_id'), (int) post('criterion_id'), ['result' => post('result'), 'remarks' => post('remarks')], $user);
        flash_set('Evaluation recorded.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'finalize' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('technical_eval.manage'); csrf_check();
        $reasons = [];
        foreach ((array) (post('rejection_reasons', [])) as $bidderId => $r) {
            if ($r !== '') $reasons[(int) $bidderId] = $r;
        }
        evaluation_finalize((int) seg(1), ['rejection_reasons' => $reasons], $user);
        flash_set('Technical evaluation finalised.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'financial-bid' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('financial_eval.manage'); csrf_check();
        financial_bid_record((int) seg(1), (int) post('bidder_id'), ['total_amount_minor' => to_minor((string) post('total_amount'))], $user);
        flash_set('Financial bid recorded.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'rank' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('financial_eval.manage'); csrf_check();
        ranking_compute((int) seg(1), $user);
        flash_set('Rankings computed.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

if (seg(0) === 'tenders' && is_numeric(seg(1) ?? '') && seg(2) === 'award' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('award.manage'); csrf_check();
        $a = award_recommend((int) seg(1), ['contractor_id' => (int) post('contractor_id'), 'awarded_amount_minor' => to_minor((string) post('awarded_amount')), 'remarks' => post('remarks')], $user);
        flash_set('Award recommended.', 'success');
        header('Location: ' . app_url('/tenders/' . seg(1))); exit;
    }, '/tenders');
    exit;
}

// ===========================================================================
// AWARDS
// ===========================================================================
if (seg(0) === 'awards' && is_numeric(seg(1) ?? '') && seg(2) === 'approve' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('award.approve'); csrf_check();
        $a = award_approve((int) seg(1), ['authority' => post('authority'), 'date' => post('date')], $user);
        flash_set('Award approved.', 'success');
        header('Location: ' . app_url('/tenders/' . $a['tender_id'])); exit;
    }, '/tenders');
    exit;
}
if (seg(0) === 'awards' && is_numeric(seg(1) ?? '') && seg(2) === 'loa' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('award.manage'); csrf_check();
        $r = award_issue_loa((int) seg(1), $user);
        flash_set('LOA issued. <a href="' . app_url('/documents/' . $r['document']['id']) . '">View LOA</a>', 'success');
        header('Location: ' . app_url('/tenders/' . $r['award']['tender_id'])); exit;
    }, '/tenders');
    exit;
}
if (seg(0) === 'awards' && is_numeric(seg(1) ?? '') && seg(2) === 'agreement' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('award.manage'); csrf_check();
        $ag = award_create_agreement((int) seg(1), ['execution_date' => post('execution_date'), 'completion_period_days' => (int) post('completion_period_days', 0), 'security_deposit_minor' => money_in('security_deposit_minor')], $user);
        flash_set('Agreement ' . $ag['agreement_number'] . ' recorded.', 'success');
        header('Location: ' . app_url('/tenders/' . $ag['tender_id'])); exit;
    }, '/tenders');
    exit;
}
if (seg(0) === 'awards' && is_numeric(seg(1) ?? '') && seg(2) === 'work-order' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('award.manage'); csrf_check();
        $wo = award_issue_work_order((int) seg(1), ['start_date' => post('start_date'), 'completion_date' => post('completion_date')], $user);
        $doc = document_generate_work_order((int) seg(1), $user);
        flash_set('Work order ' . $wo['work_order_number'] . ' issued. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/tenders/' . $wo['tender_id'])); exit;
    }, '/tenders');
    exit;
}

// ===========================================================================
// PROJECTS & EXECUTION
// ===========================================================================
if ($reqPath === '/projects') {
    guard('project.view');
    render_page('Projects', projects_list_html($user), 'projects');
    exit;
}

if ($reqPath === '/projects/new') {
    guard('project.manage');
    render_page('New Project', project_form_html($user), 'projects');
    exit;
}

if ($reqPath === '/projects/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('project.manage'); csrf_check();
        $fy = DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) post('fy_id')]);
        if (!$fy) { throw validation('Select a valid financial year.'); }
        if ($fy['status'] === 'closed') { throw conflict('Cannot create a project in a closed financial year.'); }
        if (trim((string) post('work_name')) === '') { throw validation('Work name is required.'); }
        $adminAmount = money_in('admin_approval_amount');
        $techAmount = money_in('tech_sanction_amount');
        $estimateAmount = money_in('estimate_amount');
        $sanctionedAmount = money_in('sanctioned_amount');
        if ($estimateAmount === null || $estimateAmount <= 0) { throw validation('Estimate amount must be positive.'); }
        foreach (['Admin approval amount' => $adminAmount, 'Technical sanction amount' => $techAmount, 'Sanctioned amount' => $sanctionedAmount] as $label => $amount) {
            if ($amount !== null && $amount < 0) { throw validation($label . ' cannot be negative.'); }
        }
        $id = DB::insert(
            'INSERT INTO projects (uid, panchayat_id, fy_id, scheme_id, fund_id, work_name, description, location, project_code,
               administrative_approval_no, administrative_approval_date, administrative_approval_authority, administrative_approval_amount_minor,
               technical_sanction_no, technical_sanction_date, technical_sanction_authority, technical_sanction_amount_minor,
               estimate_amount_minor, sanctioned_amount_minor, head_of_account, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [uid(), actor_panchayat_id($user), (int) $fy['id'], int_or_null(post('scheme_id')), int_or_null(post('fund_id')), post('work_name'), post('description'), post('location'), post('project_code') ?: null,
             post('admin_approval_no'), date_or_null(post('admin_approval_date')), post('admin_approval_authority'), $adminAmount,
             post('tech_sanction_no'), date_or_null(post('tech_sanction_date')), post('tech_sanction_authority'), $techAmount,
             $estimateAmount, $sanctionedAmount, post('head_of_account'), 'planned', (int) $user['id']]
        );
        audit_record('project.create', 'project', $id, post('work_name'));
        flash_set('Project created.', 'success');
        header('Location: ' . app_url('/projects/' . $id)); exit;
    }, '/projects/new');
    exit;
}

if (seg(0) === 'projects' && is_numeric(seg(1) ?? '') && seg(2) === null) {
    guard('project.view');
    render_page('Project', project_detail_html((int) seg(1), $user), 'projects');
    exit;
}

if (seg(0) === 'projects' && is_numeric(seg(1) ?? '') && seg(2) === 'progress' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('execution.manage'); csrf_check();
        execution_record_progress((int) seg(1), ['progress_date' => post('progress_date'), 'physical_progress' => (float) post('physical_progress', 0), 'financial_progress' => (float) post('financial_progress', 0), 'notes' => post('notes'), 'milestone' => post('milestone')], $user);
        flash_set('Progress recorded.', 'success');
        header('Location: ' . app_url('/projects/' . seg(1))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'projects' && is_numeric(seg(1) ?? '') && seg(2) === 'extension' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('execution.manage'); csrf_check();
        execution_extension_request((int) seg(1), ['requested_days' => (int) post('requested_days', 0), 'reason' => post('reason')], $user);
        flash_set('Extension request recorded.', 'success');
        header('Location: ' . app_url('/projects/' . seg(1))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'projects' && is_numeric(seg(1) ?? '') && seg(2) === 'measurement' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('measurement.manage'); csrf_check();
        $m = measurement_create((int) seg(1), ['measurement_date' => post('measurement_date'), 'location' => post('location'), 'remarks' => post('remarks')], $user);
        flash_set('Measurement ' . $m['measurement_number'] . ' created.', 'success');
        header('Location: ' . app_url('/projects/' . seg(1))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'measurements' && is_numeric(seg(1) ?? '') && seg(2) === 'item' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('measurement.manage'); csrf_check();
        $mid = (int) seg(1);
        measurement_add_item($mid, ['boq_item_id' => post('boq_item_id'), 'item_no' => post('item_no'), 'description' => post('description'), 'unit' => post('unit'), 'previous_quantity' => (float) post('previous_quantity', 0), 'current_quantity' => (float) post('current_quantity', 0), 'rate_minor' => to_minor((string) post('rate')), 'remarks' => post('remarks')], $user);
        flash_set('Measurement item added.', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM measurements WHERE id = ?', [$mid]))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'measurements' && is_numeric(seg(1) ?? '') && seg(2) === 'status' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('measurement.approve'); csrf_check();
        $mid = (int) seg(1);
        measurement_set_status($mid, (string) post('status'), $user);
        flash_set('Measurement status updated.', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM measurements WHERE id = ?', [$mid]))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'projects' && is_numeric(seg(1) ?? '') && seg(2) === 'bill' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('bill.manage'); csrf_check();
        $b = bill_create((int) seg(1), ['bill_type' => post('bill_type'), 'bill_date' => post('bill_date')], $user);
        flash_set('Bill ' . $b['bill_number'] . ' created.', 'success');
        header('Location: ' . app_url('/projects/' . seg(1))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'bills' && is_numeric(seg(1) ?? '') && seg(2) === 'update' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('bill.manage'); csrf_check();
        $bid = (int) seg(1);
        bill_update($bid, ['grossWorkValueMinor' => to_minor((string) post('gross_work_value')), 'previousCertifiedMinor' => to_minor((string) post('previous_certified')), 'retentionPct' => (float) post('retention_pct', 0), 'taxAmountMinor' => to_minor((string) post('tax_amount')), 'deductionsMinor' => to_minor((string) post('deductions')), 'recoveriesMinor' => to_minor((string) post('recoveries')), 'remarks' => post('remarks')], $user);
        flash_set('Bill updated.', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM bills WHERE id = ?', [$bid]))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'bills' && is_numeric(seg(1) ?? '') && seg(2) === 'submit' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('bill.manage'); csrf_check();
        $bid = (int) seg(1);
        bill_submit($bid, $user);
        flash_set('Bill submitted.', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM bills WHERE id = ?', [$bid]))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'bills' && is_numeric(seg(1) ?? '') && seg(2) === 'workflow' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('bill.approve'); csrf_check();
        $bid = (int) seg(1);
        bill_workflow_action($bid, (string) post('action', 'approve'), post('remarks'), $user);
        flash_set('Bill workflow action recorded.', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM bills WHERE id = ?', [$bid]))); exit;
    }, '/projects');
    exit;
}


if (seg(0) === 'bills' && is_numeric(seg(1) ?? '') && seg(2) === 'pdf' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('bill.manage'); csrf_check();
        $bid = (int) seg(1);
        $doc = document_generate_bill($bid, $user);
        flash_set('Bill PDF generated. <a href="' . app_url('/documents/' . $doc['id']) . '">View</a>', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM bills WHERE id = ?', [$bid]))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'bills' && is_numeric(seg(1) ?? '') && seg(2) === 'payment' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('payment.manage'); csrf_check();
        $bid = (int) seg(1);
        $pay = payment_record($bid, ['payment_date' => post('payment_date'), 'net_amount_minor' => to_minor((string) post('net_amount')), 'payment_method' => post('payment_method'), 'transaction_reference' => post('transaction_reference'), 'remarks' => post('remarks')], $user);
        flash_set('Payment recorded: ' . $pay['voucher_no'] . ' (payment recorded, not executed — no bank integration).', 'success');
        header('Location: ' . app_url('/projects/' . DB::val('SELECT project_id FROM bills WHERE id = ?', [$bid]))); exit;
    }, '/projects');
    exit;
}

if (seg(0) === 'projects' && is_numeric(seg(1) ?? '') && seg(2) === 'complete' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('completion.manage'); csrf_check();
        completion_advance((int) seg(1), ['status' => post('status'), 'date' => post('date'), 'final_measurement_id' => post('final_measurement_id'), 'final_bill_id' => post('final_bill_id'), 'final_payment_id' => post('final_payment_id')], $user);
        if (post('status') === 'closed') {
            document_generate_completion((int) seg(1), $user);
        }
        flash_set('Completion status advanced.', 'success');
        header('Location: ' . app_url('/projects/' . seg(1))); exit;
    }, '/projects');
    exit;
}

// ===========================================================================
// CONTRACTORS
// ===========================================================================
if ($reqPath === '/contractors') {
    guard('contractor.view');
    render_page('Contractors', contractors_list_html($user), 'contractors');
    exit;
}
if ($reqPath === '/contractors/new') {
    guard('contractor.manage');
    render_page('New Contractor', contractor_form_html(), 'contractors');
    exit;
}
if ($reqPath === '/contractors/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('contractor.manage'); csrf_check();
        $c = contractor_create([
            'legal_name' => post('legal_name'), 'business_name' => post('business_name'), 'address' => post('address'),
            'mobile' => post('mobile'), 'email' => post('email'), 'registration_no' => post('registration_no'),
            'registration_class' => post('registration_class'), 'registration_valid_from' => post('registration_valid_from'),
            'registration_valid_to' => post('registration_valid_to'), 'pan' => post('pan'), 'gst' => post('gst'),
            'bank_name' => post('bank_name'), 'bank_account_no' => post('bank_account_no'), 'bank_ifsc' => post('bank_ifsc'),
            'experience_summary' => post('experience_summary'),
        ], $user);
        flash_set('Contractor ' . $c['contractor_code'] . ' created.', 'success');
        header('Location: ' . app_url('/contractors/' . $c['id'])); exit;
    }, '/contractors/new');
    exit;
}
if (seg(0) === 'contractors' && is_numeric(seg(1) ?? '') && seg(2) === null) {
    guard('contractor.view');
    render_page('Contractor', contractor_detail_html((int) seg(1), $user), 'contractors');
    exit;
}
if (seg(0) === 'contractors' && is_numeric(seg(1) ?? '') && seg(2) === 'document' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('contractor.manage'); csrf_check();
        contractor_upsert_document((int) seg(1), ['doc_type' => post('doc_type'), 'doc_name' => post('doc_name'), 'issued_date' => post('issued_date'), 'expiry_date' => post('expiry_date'), 'remarks' => post('remarks')], $user);
        flash_set('Contractor document added.', 'success');
        header('Location: ' . app_url('/contractors/' . seg(1))); exit;
    }, '/contractors');
    exit;
}

// ===========================================================================
// RULES & COMPLIANCE
// ===========================================================================
if ($reqPath === '/rules') {
    guard('rules.view');
    render_page('Rules & References', rules_html(), 'rules');
    exit;
}
if ($reqPath === '/compliance') {
    guard('rules.view');
    render_page('Compliance', compliance_list_html(), 'compliance');
    exit;
}
if (seg(0) === 'compliance' && is_numeric(seg(1) ?? '')) {
    guard('rules.view');
    render_page('Compliance Check', compliance_detail_html((int) seg(1), $user), 'compliance');
    exit;
}

// ===========================================================================
// REPORTS
// ===========================================================================
if ($reqPath === '/reports') {
    guard('report.view');
    render_page('Reports', reports_html(), 'reports');
    exit;
}
if ($reqPath === '/reports/reconciliation') {
    guard('report.view');
    render_page('Reconciliation', reconciliation_html(), 'reports');
    exit;
}
if (in_array($reqPath, ['/reports/tenders.csv', '/reports/tenders-csv'], true)) {
    guard('report.export');
    $fyId = (int) ($_GET['fy_id'] ?? 0);
    $scope = scope_condition('t');
    $where = 'WHERE t.deleted_at IS NULL' . $scope['sql'];
    $params = $scope['params'];
    if ($fyId) { $where .= ' AND t.fy_id = ?'; $params[] = $fyId; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tenders.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['tender_number', 'fy', 'work_name', 'status', 'estimated_cost', 'tender_value', 'publication_date', 'bid_close_date']);
    foreach (DB::all("SELECT t.*, f.label fy_label FROM tenders t LEFT JOIN financial_years f ON f.id = t.fy_id $where ORDER BY t.id", $params) as $t) {
        fputcsv($out, [
            $t['tender_number'], $t['fy_label'], $t['work_name'] ?: $t['title'], $t['status'],
            from_minor($t['estimated_cost_minor']), from_minor($t['tender_value_minor']),
            $t['publication_date'], $t['bid_close_date'],
        ]);
    }
    fclose($out);
    exit;
}

if (in_array($reqPath, ['/reports/tenders.pdf', '/reports/tenders-pdf'], true)) {
    guard('report.export');
    $fyId = (int) ($_GET['fy_id'] ?? 0);
    $scope = scope_condition('t');
    $where = 'WHERE t.deleted_at IS NULL' . $scope['sql'];
    $params = $scope['params'];
    if ($fyId) { $where .= ' AND t.fy_id = ?'; $params[] = $fyId; }
    $rows = DB::all("SELECT t.*, f.label fy_label FROM tenders t LEFT JOIN financial_years f ON f.id = t.fy_id $where ORDER BY t.id", $params);
    $lines = ['Tender Register', 'Generated: ' . date('Y-m-d H:i:s'), ''];
    foreach ($rows as $t) {
        $lines[] = $t['tender_number'] . ' | FY ' . ($t['fy_label'] ?? '-') . ' | ' . ($t['work_name'] ?: $t['title']);
        $lines[] = 'Status: ' . $t['status'] . ' | Estimate: ' . inr($t['estimated_cost_minor']) . ' | Tender value: ' . inr($t['tender_value_minor']);
        $lines[] = 'Publication: ' . ($t['publication_date'] ?: '-') . ' | Bid close: ' . ($t['bid_close_date'] ?: '-');
        $lines[] = '';
    }
    if (!$rows) { $lines[] = 'No tender records found for the selected filters.'; }
    if (!is_dir(GENERATED_DIR)) { @mkdir(GENERATED_DIR, 0775, true); }
    $tmp = tempnam(GENERATED_DIR, 'tender-report-');
    if ($tmp === false) { throw err(500, 'REPORT_TEMP_FAILED', 'Unable to create report file.'); }
    doc_create_simple_pdf(implode("\n", $lines), 'Tender Register', $tmp);
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="tender-register.pdf"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ===========================================================================
// FINANCIAL YEARS
// ===========================================================================
if ($reqPath === '/financial-years') {
    guard('fy.view');
    render_page('Financial Years', fy_html($user), 'fy');
    exit;
}
if ($reqPath === '/financial-years/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('fy.manage'); csrf_check();
        $label = (string) post('label');
        $parsed = parse_fy($label);
        if (!$parsed) {
            throw validation('Invalid financial year label (expected YYYY-YY)');
        }
        DB::insert('INSERT INTO financial_years (uid, label, start_year, start_date, end_date, status, is_current) VALUES (?,?,?,?,?,?,0)',
            [uid(), $label, $parsed['startYear'], $parsed['start'], $parsed['end'], 'open']);
        flash_set('Financial year ' . $label . ' created.', 'success');
        header('Location: ' . app_url('/financial-years')); exit;
    }, '/financial-years');
    exit;
}
if (seg(0) === 'financial-years' && is_numeric(seg(1) ?? '') && seg(2) === 'close' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('fy.manage'); csrf_check();
        fy_close((int) seg(1));
        flash_set('Financial year closed.', 'success');
        header('Location: ' . app_url('/financial-years')); exit;
    }, '/financial-years');
    exit;
}

// ===========================================================================
// USERS
// ===========================================================================
if ($reqPath === '/users') {
    guard('user.view');
    render_page('Users & Roles', users_html($user), 'users');
    exit;
}
if ($reqPath === '/users/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('user.manage'); csrf_check();
        $email = strtolower(trim((string) post('email')));
        if (DB::one('SELECT id FROM users WHERE email = ?', [$email])) {
            throw conflict('A user with this email already exists');
        }
        $pass = (string) post('password');
        if (strlen($pass) < 10 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
            throw validation('Password must be at least 10 characters and include upper-case, lower-case and a number.');
        }
        $targetPanchayat = int_or_null(post('panchayat_id'));
        if ((int) ($user['is_global_admin'] ?? 0) !== 1) {
            $targetPanchayat = actor_panchayat_id($user);
            if ($targetPanchayat === null) { throw forbidden('Panchayat-scoped administrators must belong to a Panchayat before creating users.'); }
        }
        $roleId = (int) post('role_id');
        $role = DB::one('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if (!$role) { throw validation('Selected role does not exist.'); }
        $isGlobalAdmin = 0;
        if ($role['code'] === 'super_admin') {
            if ((int) ($user['is_global_admin'] ?? 0) !== 1) {
                throw forbidden('Only a global administrator can assign the Super Admin role.');
            }
            $targetPanchayat = null;
            $isGlobalAdmin = 1;
        } else {
            if ($targetPanchayat === null) {
                throw validation('Panchayat is required for non-global users.');
            }
            if (!DB::one('SELECT id FROM panchayats WHERE id = ?', [$targetPanchayat])) {
                throw validation('Selected Panchayat does not exist.');
            }
        }
        $id = DB::insert(
            'INSERT INTO users (uid, email, password_hash, name, designation, panchayat_id, is_global_admin, is_active) VALUES (?,?,?,?,?,?,?,1)',
            [uid(), $email, password_hash_secure($pass), post('name'), post('designation'), $targetPanchayat, $isGlobalAdmin]
        );
        DB::insert('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$id, $roleId]);
        audit_record('user.create', 'user', $id, $email);
        flash_set('User created.', 'success');
        header('Location: ' . app_url('/users')); exit;
    }, '/users');
    exit;
}

// ===========================================================================
// AUDIT
// ===========================================================================
if ($reqPath === '/audit') {
    guard('audit.view');
    render_page('Audit Log', audit_html(), 'audit');
    exit;
}

// ===========================================================================
// NOTIFICATIONS
// ===========================================================================
if ($reqPath === '/notifications') {
    guard('notification.view');
    render_page('Notifications', notifications_html($user), 'notifications');
    exit;
}
if ($reqPath === '/notifications/read' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('notification.view'); csrf_check();
        DB::run('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [(int) $user['id']]);
        header('Location: ' . app_url('/notifications')); exit;
    }, '/notifications');
    exit;
}

// ===========================================================================
// BACKUP / RESTORE
// ===========================================================================
if ($reqPath === '/backups') {
    $u = guard('backup.manage');
    render_page('Backups', backups_html($u), 'backups');
    exit;
}

if ($reqPath === '/backups/create' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('backup.manage'); csrf_check();
        $b = backup_create($user);
        flash_set('Backup created: ' . $b['file_name'], 'success');
        header('Location: ' . app_url('/backups'));
        exit;
    }, '/backups');
    exit;
}

if (seg(0) === 'backups' && is_numeric(seg(1) ?? '') && seg(2) === 'restore' && $reqMethod === 'POST') {
    act(function () use ($user) {
        guard('backup.manage'); csrf_check();
        if ((string) post('confirm') !== 'RESTORE') {
            throw validation('Type RESTORE to confirm full database restore.');
        }
        backup_restore((int) seg(1), $user);
        flash_set('Backup restored. You may need to sign in again if user/session data changed.', 'success');
        header('Location: ' . app_url('/backups'));
        exit;
    }, '/backups');
    exit;
}

if (seg(0) === 'backups' && is_numeric(seg(1) ?? '') && seg(2) === null) {
    $u = guard('backup.manage');
    backup_require_global_admin($u);
    $b = DB::one('SELECT * FROM backups WHERE id = ?', [(int) seg(1)]);
    if (!$b) { throw not_found('Backup not found'); }
    $file = backup_file_path((string) $b['file_name']);
    audit_record('backup.download', 'backup', (int) $b['id'], (string) $b['file_name']);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . backup_safe_file_name((string) $b['file_name']) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ===========================================================================
// SETTINGS
// ===========================================================================
if ($reqPath === '/settings') {
    guard('settings.manage');
    if ($reqMethod === 'POST') {
        act(function () {
            csrf_check();
            foreach (['numbering.prefix','numbering.tender_nit.pattern','numbering.bill.pattern','numbering.work_order.pattern','numbering.agreement.pattern','numbering.loa.pattern','contractor.expiry_warning_days'] as $k) {
                if (post($k) !== null) {
                    if (DB::one('SELECT id FROM settings WHERE ' . sql_ident('key') . ' = ?', [$k])) {
                        DB::run('UPDATE settings SET ' . sql_ident('value') . ' = ?, updated_at = CURRENT_TIMESTAMP WHERE ' . sql_ident('key') . ' = ?', [post($k), $k]);
                    } else {
                        DB::insert('INSERT INTO settings (' . sql_ident('key') . ', ' . sql_ident('value') . ') VALUES (?,?)', [$k, post($k)]);
                    }
                }
            }
            flash_set('Settings saved.', 'success');
            header('Location: ' . app_url('/settings')); exit;
        }, '/settings');
        exit;
    }
    render_page('Settings', settings_html(), 'settings');
    exit;
}

// ===========================================================================
// DOCUMENTS
// ===========================================================================
if ($reqPath === '/documents') {
    guard('document.view');
    render_page('Documents', documents_list_html($user), 'documents');
    exit;
}

if ($reqPath === '/documents/upload') {
    guard('document.upload');
    if ($reqMethod === 'POST') {
        act(function () use ($user) {
            csrf_check();
            $doc = document_upload($_FILES['document'] ?? [], [
                'entity_type' => post('entity_type'),
                'entity_id' => post('entity_id'),
                'category' => post('category', 'supporting'),
            ], $user);
            flash_set('Document uploaded: ' . $doc['original_name'], 'success');
            header('Location: ' . app_url('/documents'));
            exit;
        }, '/documents/upload');
        exit;
    }
    render_page('Upload document', document_upload_html(), 'documents');
    exit;
}

// ===========================================================================
// DOCUMENTS (download)
// ===========================================================================
if (seg(0) === 'documents' && is_numeric(seg(1) ?? '')) {
    $doc = DB::one('SELECT * FROM documents WHERE id = ?', [(int) seg(1)]);
    if (!$doc) {
        throw not_found('Document not found');
    }
    $currentViewer = current_user();
    $publicNit = false;
    if (!$currentViewer && $doc['category'] === 'nit' && $doc['entity_type'] === 'tender' && $doc['entity_id']) {
        $publicNit = (bool) DB::one("SELECT id FROM tenders WHERE id = ? AND deleted_at IS NULL AND status IN ('published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','closed')", [(int) $doc['entity_id']]);
    }
    if (!$publicNit) {
        guard('document.download');
        scope_assert_row($doc, $currentViewer ?: $user, 'document');
    }
    $file = document_file_path($doc);
    if (!$file) {
        throw not_found('Document file not found');
    }
    audit_record('document.download', 'document', (int) $doc['id'], $doc['original_name']);
    $mime = $doc['mime_type'] ?: 'application/octet-stream';
    $inline = in_array($mime, ['application/pdf', 'text/plain', 'text/csv'], true) || str_starts_with($mime, 'image/');
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $doc['original_name']) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ---------------------------------------------------------------------------
// Fallback
// ---------------------------------------------------------------------------
http_response_code(404);
render_page('Not Found', '<h1>404</h1><p class="muted">Page not found.</p>', '');

// ===========================================================================
// PAGE BUILDERS
// ===========================================================================

function login_form_html(): string
{
    return '<div class="card login-card"><h1>Sign in</h1>'
        . h_form_open('/login', 'post')
        . h_input('Email', 'email', '', 'email', true)
        . h_input('Password', 'password', '', 'password', true)
        . h_submit('Sign in')
        . '</form></div>';
}

function dashboard_html(array $user): string
{
    $fyId = (int) ($_GET['fy_id'] ?? 0) ?: (fy_current() ? (int) fy_current()['id'] : 0);
    $d = report_dashboard($fyId ?: null);
    $cards = '<div class="grid grid-4">'
        . stat_card($d['tenders']['total'] ?? 0, 'Tenders', 'across all statuses')
        . stat_card($d['projects']['total'] ?? 0, 'Projects', ($d['projects']['ongoing'] ?? 0) . ' ongoing')
        . stat_card($d['bills']['total'] ?? 0, 'Bills', ($d['bills']['pending'] ?? 0) . ' pending')
        . stat_card(inr($d['payments']['paidMinor'] ?? 0), 'Payments recorded', 'net paid amount')
        . '</div>';
    $cards .= '<div class="grid grid-4" style="margin-top:14px">'
        . stat_card(inr($d['awards']['awardedMinor'] ?? 0), 'Awarded value', 'awards to date')
        . stat_card((string) ($d['complianceIssues'] ?? 0), 'Compliance issues', 'blocking/warning/verification')
        . stat_card((string) ($d['pendingApprovals'] ?? 0), 'Pending approvals', 'workflow steps')
        . stat_card(inr($d['tenders']['tender_value'] ?? 0), 'Tender value', 'published tenders')
        . '</div>';

    $pipeRows = [];
    foreach ($d['statusPipeline'] as $r) {
        $pipeRows[] = ['<code>' . e($r['status']) . '</code>', '<span class="num">' . (int) $r['c'] . '</span>'];
    }
    $actRows = [];
    foreach ($d['recentActivity'] as $a) {
        $actRows[] = [h_date($a['created_at']), e($a['action']), e($a['entity_type'] . '#' . ($a['entity_id'] ?? '')), e($a['actor_name'] ?? 'system')];
    }
    return '<div class="page-head"><h1>Dashboard</h1>' . fy_filter_form($fyId ?: null) . '</div>'
        . $cards
        . '<div class="grid grid-2"><div class="card"><h2>Status pipeline</h2>' . h_table(['Status', 'Count'], $pipeRows) . '</div>'
        . '<div class="card"><h2>Recent activity</h2>' . h_table(['When', 'Action', 'Entity', 'Actor'], $actRows) . '</div></div>';
}

function stat_card(string|int|float $value, string $label, string $sub = ''): string
{
    return '<div class="stat"><div class="stat-value">' . e((string) $value) . '</div><div class="stat-label">' . e($label) . '</div>'
        . ($sub ? '<div class="stat-sub">' . e($sub) . '</div>' : '') . '</div>';
}

function fy_filter_form(?int $selected): string
{
    $opts = [];
    foreach (fy_list() as $f) {
        $opts[$f['id']] = $f['label'];
    }
    return '<form method="get" action="' . e(app_url('/')) . '" class="actions">' . h_select('Financial year', 'fy_id', $opts, $selected) . h_submit('Filter', 'btn-outline') . '</form>';
}


function panchayat_settings_html(array $user): string
{
    $p = current_panchayat();
    if (!$p) {
        return '<div class="card"><h1>Panchayat Settings</h1><p class="muted">No Panchayat exists yet. Run the installer/seed to create the initial Panchayat configuration.</p></div>';
    }
    $canEdit = user_has_permission($user, 'panchayat.manage');
    $form = '<div class="card"><h1>Panchayat Settings</h1>';
    $form .= '<p class="muted">Configure the local office identity used on generated documents. This portal does not represent itself as the official Government of West Bengal e-procurement platform.</p>';
    if (!$canEdit) {
        return $form . '<dl class="dl">'
            . dt_dd('State', e($p['state'])) . dt_dd('District', e($p['district'])) . dt_dd('Block', e($p['block']))
            . dt_dd('Gram Panchayat', e($p['gram_panchayat'])) . dt_dd('GP Code', e($p['gp_code']))
            . dt_dd('Office Address', e($p['office_address'])) . dt_dd('Phone', e($p['phone'])) . dt_dd('Email', e($p['email'])) . '</dl></div>';
    }
    $form .= h_form_open('/panchayat', 'post') . '<input type="hidden" name="id" value="' . (int) $p['id'] . '">'
        . '<div class="field-row">' . h_input('State', 'state', $p['state'], 'text', true) . h_input('District', 'district', $p['district']) . '</div>'
        . '<div class="field-row">' . h_input('Block', 'block', $p['block']) . h_input('Gram Panchayat', 'gram_panchayat', $p['gram_panchayat'], 'text', true) . '</div>'
        . '<div class="field-row">' . h_input('GP Code', 'gp_code', $p['gp_code']) . h_input('PIN', 'pin', $p['pin']) . '</div>'
        . h_textarea('Office Address', 'office_address', $p['office_address'], 3)
        . '<div class="field-row">' . h_input('Official phone', 'phone', $p['phone']) . h_input('Official email', 'email', $p['email'], 'email') . '</div>'
        . '<div class="field-row">' . h_input('Pradhan', 'pradhan', $p['pradhan']) . h_input('Upa-Pradhan', 'upa_pradhan', $p['upa_pradhan']) . '</div>'
        . '<div class="field-row">' . h_input('Panchayat Secretary', 'panchayat_secretary', $p['panchayat_secretary']) . h_input('Technical Officer', 'technical_officer', $p['technical_officer']) . '</div>'
        . h_input('Accounts Officer', 'accounts_officer', $p['accounts_officer'])
        . h_textarea('Letterhead HTML / text', 'letterhead_html', $p['letterhead_html'], 3)
        . h_textarea('Document header', 'document_header_html', $p['document_header_html'], 3)
        . h_textarea('Document footer', 'document_footer_html', $p['document_footer_html'], 3)
        . h_submit('Save Panchayat settings', 'btn-primary') . '</form></div>';
    return $form;
}

function schemes_html(array $user): string
{
    $rows = [];
    foreach (DB::all('SELECT * FROM schemes ORDER BY name') as $s) {
        $rows[] = [e($s['name']), e($s['code'] ?: '—'), e($s['sponsoring_authority'] ?: '—'), (int) $s['is_active'] ? '<span class="badge badge-active">ACTIVE</span>' : '<span class="badge badge-cancelled">INACTIVE</span>'];
    }
    $form = user_has_permission($user, 'scheme.manage')
        ? '<div class="card"><h2>Add Scheme</h2>' . h_form_open('/schemes/create', 'post')
            . '<div class="field-row">' . h_input('Scheme name', 'name', '', 'text', true) . h_input('Scheme code', 'code') . '</div>'
            . h_input('Department / authority', 'sponsoring_authority')
            . h_textarea('Description', 'description', '', 2)
            . h_submit('Create Scheme', 'btn-primary') . '</form></div>'
        : '';
    return '<div class="page-head"><h1>Schemes</h1>' . h_link('Funds', '/funds', 'btn-outline') . h_link('Budgets', '/budgets', 'btn-outline') . '</div>' . $form
        . h_table(['Scheme', 'Code', 'Authority', 'Status'], $rows);
}

function funds_html(array $user): string
{
    $rows = [];
    foreach (DB::all('SELECT * FROM funds ORDER BY name') as $f) {
        $rows[] = [e($f['name']), e($f['code'] ?: '—'), e($f['funding_source'] ?: '—'), e($f['head_of_account'] ?: '—'), (int) $f['is_active'] ? '<span class="badge badge-active">ACTIVE</span>' : '<span class="badge badge-cancelled">INACTIVE</span>'];
    }
    $form = user_has_permission($user, 'scheme.manage')
        ? '<div class="card"><h2>Add Fund</h2>' . h_form_open('/funds/create', 'post')
            . '<div class="field-row">' . h_input('Fund name', 'name', '', 'text', true) . h_input('Fund code', 'code') . '</div>'
            . '<div class="field-row">' . h_input('Funding source', 'funding_source') . h_input('Head of account', 'head_of_account') . '</div>'
            . h_submit('Create Fund', 'btn-primary') . '</form></div>'
        : '';
    return '<div class="page-head"><h1>Funds</h1>' . h_link('Schemes', '/schemes', 'btn-outline') . h_link('Budgets', '/budgets', 'btn-outline') . '</div>' . $form
        . h_table(['Fund', 'Code', 'Source', 'Head of Account', 'Status'], $rows);
}

function budgets_html(array $user): string
{
    $fyOpts = []; foreach (fy_list() as $f) { $fyOpts[$f['id']] = $f['label']; }
    $schemeOpts = []; foreach (DB::all('SELECT id, name FROM schemes ORDER BY name') as $s) { $schemeOpts[$s['id']] = $s['name']; }
    $fundOpts = []; foreach (DB::all('SELECT id, name FROM funds ORDER BY name') as $f) { $fundOpts[$f['id']] = $f['name']; }
    $scope = scope_condition('ba');
    $rows = [];
    foreach (DB::all('SELECT ba.*, fy.label fy_label, s.name scheme_name, f.name fund_name FROM budget_allocations ba LEFT JOIN financial_years fy ON fy.id=ba.fy_id LEFT JOIN schemes s ON s.id=ba.scheme_id LEFT JOIN funds f ON f.id=ba.fund_id WHERE 1=1' . $scope['sql'] . ' ORDER BY fy.start_date DESC, ba.id DESC', $scope['params']) as $b) {
        $committed = (int) DB::val('SELECT COALESCE(SUM(tender_value_minor),0) FROM tenders WHERE fy_id = ? AND deleted_at IS NULL AND status NOT IN (\'cancelled\')' . panchayat_scope_sql(), [(int) $b['fy_id']]);
        $paid = (int) DB::val('SELECT COALESCE(SUM(net_amount_minor),0) FROM payments WHERE fy_id = ? AND status = \'recorded\'' . panchayat_scope_sql(), [(int) $b['fy_id']]);
        $rows[] = [e($b['fy_label']), e($b['scheme_name'] ?: '—'), e($b['fund_name'] ?: '—'), '<span class="num">' . h_money($b['sanctioned_amount_minor']) . '</span>', '<span class="num">' . h_money($committed) . '</span>', '<span class="num">' . h_money($paid) . '</span>', '<span class="num">' . h_money((int) $b['sanctioned_amount_minor'] - $paid) . '</span>'];
    }
    $form = user_has_permission($user, 'scheme.manage')
        ? '<div class="card"><h2>Add Budget Allocation</h2>' . h_form_open('/budgets/create', 'post')
            . '<div class="field-row">' . h_select('Financial year', 'fy_id', $fyOpts, fy_current() ? fy_current()['id'] : null, true) . h_select('Scheme', 'scheme_id', $schemeOpts) . '</div>'
            . '<div class="field-row">' . h_select('Fund', 'fund_id', $fundOpts) . h_input('Sanctioned amount (₹)', 'sanctioned_amount', '', 'number', true) . '</div>'
            . h_submit('Record Budget', 'btn-primary') . '</form></div>'
        : '';
    return '<div class="page-head"><h1>Budgets</h1>' . h_link('Schemes', '/schemes', 'btn-outline') . h_link('Funds', '/funds', 'btn-outline') . '</div>' . $form
        . '<div class="card"><p class="muted">Budget figures are stored as allocations. Tender commitments, payments and balances below are calculated from source records.</p></div>'
        . h_table(['FY', 'Scheme', 'Fund', 'Budget', 'Tender Commitments', 'Paid', 'Remaining vs Paid'], $rows);
}

function procurement_plans_html(array $user): string
{
    $fyOpts = []; foreach (fy_list() as $f) { $fyOpts[$f['id']] = $f['label']; }
    $schemeOpts = []; foreach (DB::all('SELECT id, name FROM schemes ORDER BY name') as $s) { $schemeOpts[$s['id']] = $s['name']; }
    $fundOpts = []; foreach (DB::all('SELECT id, name FROM funds ORDER BY name') as $f) { $fundOpts[$f['id']] = $f['name']; }
    $scope = scope_condition('pp');
    $rows = [];
    foreach (DB::all('SELECT pp.*, fy.label fy_label, s.name scheme_name, f.name fund_name FROM procurement_plans pp LEFT JOIN financial_years fy ON fy.id=pp.fy_id LEFT JOIN schemes s ON s.id=pp.scheme_id LEFT JOIN funds f ON f.id=pp.fund_id WHERE 1=1' . $scope['sql'] . ' ORDER BY pp.id DESC', $scope['params']) as $pl) {
        $actions = '';
        if (user_has_permission($user, 'plan.manage')) {
            if (empty($pl['project_id'])) {
                $actions .= h_form_open('/procurement-plans/' . $pl['id'] . '/project', 'post') . '<button class="btn btn-small">Convert to Project</button></form>';
            } else {
                $actions .= '<a href="' . e(app_url('/projects/' . $pl['project_id'])) . '">Project #' . (int) $pl['project_id'] . '</a> ';
            }
            if ($pl['status'] !== 'tender_created') {
                $actions .= h_form_open('/procurement-plans/' . $pl['id'] . '/tender', 'post') . '<button class="btn btn-small btn-primary">Convert to Tender</button></form>';
            }
        }
        $rows[] = [e($pl['work_name']), e($pl['fy_label']), e($pl['scheme_name'] ?: '—'), e($pl['fund_name'] ?: '—'), '<span class="num">' . h_money($pl['estimated_amount_minor']) . '</span>', e($pl['procurement_method'] ?: '—'), h_date($pl['expected_date']), h_status($pl['status'], ['planned' => 'draft', 'initiated' => 'info', 'tender_created' => 'approved', 'cancelled' => 'cancelled']), $actions];
    }
    $form = user_has_permission($user, 'plan.manage')
        ? '<div class="card"><h2>Add Procurement Plan</h2>' . h_form_open('/procurement-plans/create', 'post')
            . '<div class="field-row">' . h_select('Financial year', 'fy_id', $fyOpts, fy_current() ? fy_current()['id'] : null, true) . h_input('Work / procurement title', 'work_name', '', 'text', true) . '</div>'
            . '<div class="field-row">' . h_select('Scheme', 'scheme_id', $schemeOpts) . h_select('Fund', 'fund_id', $fundOpts) . '</div>'
            . '<div class="field-row">' . h_input('Estimated amount (₹)', 'estimated_amount', '', 'number', true) . h_input('Expected tender date', 'expected_date', '', 'date') . '</div>'
            . '<div class="field-row">' . h_input('Procurement method', 'procurement_method', 'Open Tender') . h_input('Responsible officer', 'responsible_officer') . '</div>'
            . h_textarea('Budget provision', 'budget_provision', '', 2)
            . h_textarea('Remarks', 'remarks', '', 2)
            . h_submit('Create Plan', 'btn-primary') . '</form></div>'
        : '';
    return '<div class="page-head"><h1>Annual Procurement Plan</h1></div>' . $form
        . h_table(['Work', 'FY', 'Scheme', 'Fund', 'Estimate', 'Method', 'Expected', 'Status', 'Actions'], $rows);
}

function tenders_list_html(array $user): string
{
    $rows = [];
    $map = ['draft' => 'draft', 'under_approval' => 'under_approval', 'approved' => 'approved', 'nit_generated' => 'approved', 'published' => 'published', 'bidding' => 'bidding', 'bid_closed' => 'bid_closed', 'technical_evaluation' => 'technical_evaluation', 'financial_evaluation' => 'financial_evaluation', 'awarded' => 'awarded', 'cancelled' => 'cancelled', 'retendered' => 'retendered', 'closed' => 'closed'];
    $scope = scope_condition('t');
    foreach (DB::all('SELECT t.*, f.label fy_label FROM tenders t LEFT JOIN financial_years f ON f.id = t.fy_id WHERE t.deleted_at IS NULL' . $scope['sql'] . ' ORDER BY t.id DESC', $scope['params']) as $t) {
        $rows[] = [
            '<a href="' . e(app_url('/tenders/' . $t['id'])) . '">' . e($t['tender_number']) . '</a>',
            e($t['work_name'] ?: $t['title']),
            e($t['fy_label']),
            h_status($t['status'], $map),
            '<span class="num">' . h_money($t['tender_value_minor'] ?: $t['estimated_cost_minor']) . '</span>',
        ];
    }
    return '<div class="page-head"><h1>Tenders</h1>' . (user_has_permission($user, 'tender.manage') ? h_link('+ New Tender', '/tenders/new', 'btn-primary') : '') . '</div>'
        . h_table(['Number', 'Work', 'FY', 'Status', 'Value'], $rows);
}

function tender_form_html(?array $t, array $user): string
{
    $fyOpts = [];
    foreach (fy_list() as $f) {
        $fyOpts[$f['id']] = $f['label'];
    }
    $projOpts = [];
    $pscope = scope_condition();
    foreach (DB::all('SELECT id, work_name FROM projects WHERE deleted_at IS NULL' . $pscope['sql'] . ' ORDER BY id DESC', $pscope['params']) as $p) {
        $projOpts[$p['id']] = $p['work_name'];
    }
    $schemeOpts = [];
    foreach (DB::all('SELECT id, name FROM schemes') as $s) {
        $schemeOpts[$s['id']] = $s['name'];
    }
    $fundOpts = [];
    foreach (DB::all('SELECT id, name FROM funds') as $f) {
        $fundOpts[$f['id']] = $f['name'];
    }
    $rsOpts = [];
    foreach (DB::all('SELECT id, name FROM rulesets WHERE is_active = 1') as $r) {
        $rsOpts[$r['id']] = $r['name'];
    }
    $sel = fn($v) => h_select($v, $v, [], '');
    return '<div class="card"><h1>New Tender</h1>'
        . h_form_open('/tenders/create', 'post')
        . '<div class="field-row">' . h_select('Financial year *', 'fy_id', $fyOpts, fy_current() ? fy_current()['id'] : null, true) . h_select('Procurement category *', 'procurement_category', ['works' => 'Works', 'supply' => 'Supply', 'service' => 'Services'], 'works', true) . '</div>'
        . '<div class="field-row">' . h_select('Tender type *', 'tender_type', ['open' => 'Open', 'limited' => 'Limited', 'e-tender' => 'e-Tender', 'single' => 'Single', 'short-notice' => 'Short Notice'], 'open', true) . h_input('Procurement method', 'procurement_method', '', 'text') . '</div>'
        . h_input('Title *', 'title', '', 'text', true)
        . h_input('Work name', 'work_name', '', 'text')
        . '<div class="field-row">' . h_select('Project', 'project_id', $projOpts, null) . h_select('Scheme', 'scheme_id', $schemeOpts, null) . '</div>'
        . '<div class="field-row">' . h_select('Fund', 'fund_id', $fundOpts, null) . h_select('Rule set', 'ruleset_id', $rsOpts, null) . '</div>'
        . h_input('Location', 'location', '', 'text')
        . h_textarea('Description', 'description', '', 3)
        . h_submit('Create Tender', 'btn-primary')
        . '</form></div>';
}

function tender_detail_html(int $id, array $user): string
{
    $t = tender_get($id);
    $fy = DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) $t['fy_id']]);
    $project = $t['project_id'] ? DB::one('SELECT * FROM projects WHERE id = ?', [(int) $t['project_id']]) : null;
    $boq = DB::all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [$id]);
    $steps = workflow_get('tender', $id);
    $compliance = compliance_get_latest('tender', $id);
    $bidders = bidder_list($id);
    $award = DB::one('SELECT * FROM awards WHERE tender_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    $docs = DB::all('SELECT * FROM documents WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC', ['tender', $id]);
    $map = ['draft' => 'draft', 'under_approval' => 'under_approval', 'approved' => 'approved', 'nit_generated' => 'approved', 'published' => 'published', 'bidding' => 'bidding', 'bid_closed' => 'bid_closed', 'technical_evaluation' => 'technical_evaluation', 'financial_evaluation' => 'financial_evaluation', 'awarded' => 'awarded', 'cancelled' => 'cancelled', 'retendered' => 'retendered', 'closed' => 'closed'];

    $out = '<div class="page-head"><h1>' . e($t['tender_number']) . ' ' . h_status($t['status'], $map) . '</h1>' . h_back('/tenders') . '</div>';

    // Actions
    $actions = '<div class="card"><h2>Actions</h2><div class="actions">';
    if ($t['status'] === 'draft' && user_has_permission($user, 'tender.manage')) {
        $actions .= edit_tender_form_trigger($t);
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/submit')) . '">' . csrf_field() . '<button class="btn btn-primary">Submit for approval</button></form>';
    }
    if ($t['status'] === 'under_approval' && user_has_permission($user, 'tender.approve')) {
        $actions .= workflow_buttons('tenders', $id);
    }
    if (in_array($t['status'], ['approved', 'nit_generated'], true) && user_has_permission($user, 'nit.manage')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/nit')) . '">' . csrf_field() . '<button class="btn btn-accent">Generate NIT</button></form>';
    }
    if (user_has_permission($user, 'nit.manage')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/tender-document')) . '">' . csrf_field() . '<button class="btn">Generate tender document PDF</button></form>';
    }
    if (user_has_permission($user, 'boq.manage')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/boq-pdf')) . '">' . csrf_field() . '<button class="btn">Generate BOQ PDF</button></form>';
    }
    if (in_array($t['status'], ['financial_evaluation', 'awarded', 'closed'], true) && user_has_permission($user, 'technical_eval.manage')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/technical-evaluation-pdf')) . '">' . csrf_field() . '<button class="btn">Generate technical evaluation PDF</button></form>';
    }
    if (in_array($t['status'], ['financial_evaluation', 'awarded', 'closed'], true) && user_has_permission($user, 'comparative.view')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/comparative-pdf')) . '">' . csrf_field() . '<button class="btn">Generate comparative PDF</button></form>';
    }
    if (user_has_permission($user, 'document.download')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/complete-file')) . '">' . csrf_field() . '<button class="btn">Generate complete tender file</button></form>';
    }
    if (in_array($t['status'], ['approved', 'nit_generated'], true) && user_has_permission($user, 'tender.publish')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/publish')) . '" class="inline">' . csrf_field() . '<button class="btn btn-primary">Publish</button></form>';
    }
    if ($t['status'] === 'published' && user_has_permission($user, 'tender.publish')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/start-bidding')) . '">' . csrf_field() . '<button class="btn btn-primary">Start bidding</button></form>';
    }
    if (in_array($t['status'], ['published', 'bidding'], true) && user_has_permission($user, 'tender.publish')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/close-bids')) . '">' . csrf_field() . '<button class="btn">Close bids</button></form>';
    }
    if (user_has_permission($user, 'rules.view')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/compliance')) . '">' . csrf_field() . '<button class="btn btn-outline">Run compliance</button></form>';
    }
    if (!in_array($t['status'], ['awarded', 'closed', 'cancelled'], true) && user_has_permission($user, 'tender.corrigendum')) {
        $actions .= '<a class="btn" href="#corrigendum">Corrigendum</a>';
    }
    if (!in_array($t['status'], ['retendered', 'awarded', 'closed', 'cancelled'], true) && user_has_permission($user, 'tender.retender')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/retender')) . '" data-confirm="Create a re-tender from this tender?">' . csrf_field() . '<button class="btn">Re-tender</button></form>';
    }
    if (!in_array($t['status'], ['awarded', 'closed', 'cancelled'], true) && user_has_permission($user, 'tender.cancel')) {
        $actions .= '<form method="post" action="' . e(app_url('/tenders/' . $id . '/cancel')) . '" data-confirm="Cancel this tender? This cannot be undone.">' . csrf_field() . '<button class="btn btn-danger">Cancel tender</button></form>';
    }
    $actions .= '</div></div>';

    // Key info
    $info = '<div class="card"><h2>Details</h2><dl class="dl">'
        . dt_dd('Financial Year', $fy ? $fy['label'] : '—')
        . dt_dd('Work name', $t['work_name'] ?: $t['title'])
        . dt_dd('Category / Type', $t['procurement_category'] . ' / ' . $t['tender_type'])
        . dt_dd('Location', $t['location'])
        . dt_dd('Project', $project ? $project['work_name'] : '—')
        . dt_dd('Estimated cost', h_money($t['estimated_cost_minor']))
        . dt_dd('Tender value', h_money($t['tender_value_minor']))
        . dt_dd('EMD', $t['emd_minor'] !== null ? h_money($t['emd_minor']) : '—')
        . dt_dd('Tender fee', $t['tender_fee_minor'] !== null ? h_money($t['tender_fee_minor']) : '—')
        . dt_dd('Admin approval', ($t['admin_approval_no'] ? $t['admin_approval_no'] . ' · ' . h_date($t['admin_approval_date']) : '—'))
        . dt_dd('Technical sanction', ($t['tech_sanction_no'] ? $t['tech_sanction_no'] . ' · ' . h_date($t['tech_sanction_date']) : '—'))
        . dt_dd('Publication', h_date($t['publication_date']))
        . dt_dd('Bid close', h_date($t['bid_close_date']))
        . dt_dd('Technical open', h_date($t['technical_open_date']))
        . dt_dd('Financial open', h_date($t['financial_open_date']))
        . '</dl></div>';

    // BOQ
    $boqRows = [];
    $boqTotal = 0;
    foreach ($boq as $b) {
        $amt = qty_rate($b['quantity'], $b['estimated_rate_minor']);
        $boqTotal += $amt;
        $boqRows[] = [e($b['item_no']), e($b['description']), e($b['unit']), '<span class="num">' . e(h_qty($b['quantity'])) . '</span>', '<span class="num">' . h_money($b['estimated_rate_minor']) . '</span>', '<span class="num">' . h_money($amt) . '</span>'];
    }
    $boqHtml = '<div class="card"><h2>BOQ ' . (user_has_permission($user, 'boq.manage') && $t['status'] === 'draft' ? boq_add_form($id) : '') . '</h2>'
        . h_table(['Item', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], $boqRows) . '</div>';

    // Workflow
    $stepRows = [];
    foreach ($steps as $s) {
        $stepRows[] = [e($s['step_name']), e($s['required_role'] ?: '—'), h_status($s['status'], ['pending' => 'pending', 'in_progress' => 'in_progress', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'info', 'clarification' => 'info', 'skipped' => 'info', 'cancelled' => 'cancelled']), e($s['actor_id'] ? 'acted' : ''), $s['acted_at'] ? h_date($s['acted_at']) : ''];
    }
    $wfHtml = '<div class="card"><h2>Approval workflow</h2>' . h_table(['Step', 'Required role', 'Status', 'Action', 'When'], $stepRows) . '</div>';

    // Compliance
    $compHtml = '';
    if ($compliance) {
        $fRows = [];
        foreach ($compliance['findings'] as $f) {
            $fRows[] = ['<span class="verification">' . e($f['severityLabel']) . '</span>', e($f['ruleCode']), e($f['ruleTitle']), e($f['message'])];
        }
        $compHtml = '<div class="card"><h2>Compliance (' . e($compliance['summary']['passed'] ?? '0') . ' passed, ' . e($compliance['summary']['blocking'] ?? '0') . ' blocking, ' . e($compliance['summary']['warning'] ?? '0') . ' warning, ' . e($compliance['summary']['verification_required'] ?? '0') . ' verification required, ' . e($compliance['summary']['not_applicable'] ?? '0') . ' not applicable)</h2>'
            . h_table(['Severity', 'Code', 'Rule', 'Result'], $fRows) . '</div>';
    } else {
        $compHtml = '<div class="card"><h2>Compliance</h2><p class="muted">Not evaluated yet.</p></div>';
    }

    // Bidders & evaluation
    $bidRows = [];
    foreach ($bidders as $b) {
        $bidRows[] = [e($b['legal_name'] ?: $b['business_name'] ?: $b['bidder_label']), e($b['contractor_code']), h_status($b['bid_status'], ['submitted' => 'submitted', 'technical_opened' => 'info', 'technically_qualified' => 'approved', 'technically_disqualified' => 'technically_disqualified', 'clarification_required' => 'info', 'financial_opened' => 'info', 'awarded' => 'awarded', 'rejected' => 'rejected', 'withdrawn' => 'info']), $b['rank'] !== null ? '#' . (int) $b['rank'] : '—'];
    }
    $biddersHtml = '<div class="card"><h2>Bidders</h2>'
        . (user_has_permission($user, 'bid.manage') && in_array($t['status'], ['bidding', 'bid_closed', 'technical_evaluation'], true) ? bidder_add_form($id) : '')
        . h_table(['Bidder', 'Code', 'Status', 'Rank'], $bidRows) . '</div>';

    // Evaluation tools
    $evalHtml = '';
    if ($t['status'] === 'technical_evaluation' && user_has_permission($user, 'technical_eval.manage')) {
        $evalHtml = evaluation_forms_html($id, $bidders);
    }
    if ($t['status'] === 'financial_evaluation' && user_has_permission($user, 'financial_eval.manage')) {
        $evalHtml .= financial_forms_html($id, $bidders);
    }

    // Award
    $awardHtml = '';
    if ($award) {
        $awardHtml = '<div class="card"><h2>Award</h2><dl class="dl">'
            . dt_dd('Status', h_status($award['status'], ['recommended' => 'recommended', 'approved' => 'approved', 'loa_issued' => 'loaisued', 'agreement_done' => 'agreement_done', 'work_order_issued' => 'work_order_issued', 'completed' => 'completed', 'cancelled' => 'cancelled']))
            . dt_dd('Awarded amount', h_money($award['awarded_amount_minor']))
            . dt_dd('LOA', $award['loa_number'] ? e($award['loa_number']) . ' · ' . h_date($award['loa_date']) : '—')
            . '</dl><div class="actions">';
        if ($award['status'] === 'recommended' && user_has_permission($user, 'award.approve')) {
            $awardHtml .= '<form method="post" action="' . e(app_url('/awards/' . $award['id'] . '/approve')) . '" class="inline">' . csrf_field() . '<button class="btn btn-primary">Approve award</button></form>';
        }
        if ($award['status'] === 'approved' && user_has_permission($user, 'award.manage')) {
            $awardHtml .= '<form method="post" action="' . e(app_url('/awards/' . $award['id'] . '/loa')) . '">' . csrf_field() . '<button class="btn btn-accent">Issue LOA</button></form>';
        }
        if (in_array($award['status'], ['approved', 'loa_issued'], true) && user_has_permission($user, 'award.manage')) {
            $awardHtml .= agreement_form($award);
        }
        if ($award['status'] === 'agreement_done' && user_has_permission($user, 'award.manage')) {
            $awardHtml .= work_order_form($award);
        }
        $awardHtml .= '</div></div>';
    } elseif ($t['status'] === 'financial_evaluation' && user_has_permission($user, 'award.manage')) {
        $awardHtml = '<div class="card"><h2>Recommend award</h2>' . award_form_html($id, $bidders) . '</div>';
    }

    // Documents
    $docRows = [];
    foreach ($docs as $d) {
        $docRows[] = [e($d['category']), e($d['original_name']), '<a href="' . e(app_url('/documents/' . $d['id'])) . '">View</a>', h_date($d['created_at'])];
    }
    $docsHtml = '<div class="card"><h2>Generated documents</h2>' . h_table(['Type', 'File', 'Action', 'Created'], $docRows) . '</div>';

    // Corrigendum inline form
    $corrHtml = '';
    if (!in_array($t['status'], ['awarded', 'closed', 'cancelled'], true) && user_has_permission($user, 'tender.corrigendum')) {
        $corrHtml = '<div class="card" id="corrigendum"><h2>Issue corrigendum</h2>'
            . h_form_open('/tenders/' . $id . '/corrigendum', 'post')
            . h_textarea('Reason', 'reason', '', 2)
            . h_textarea('Changes (summary)', 'changes', '', 2)
            . h_submit('Issue corrigendum', 'btn-outline') . '</form></div>';
    }

    return $out . $actions . $info . $boqHtml . $wfHtml . $compHtml . $biddersHtml . $evalHtml . $awardHtml . $docsHtml . $corrHtml;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function dt_dd(string $k, string $v): string
{
    return '<dt>' . e($k) . '</dt><dd>' . $v . '</dd>';
}

function workflow_buttons(string $entity, int $id): string
{
    $out = '';
    foreach ([['approve', 'Approve', 'btn-primary'], ['return', 'Return', 'btn-outline'], ['reject', 'Reject', 'btn-danger']] as [$action, $label, $cls]) {
        $out .= '<form method="post" action="' . e(app_url('/' . $entity . '/' . $id . '/workflow')) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="action" value="' . $action . '">'
            . '<button class="btn ' . $cls . '">' . $label . '</button></form>';
    }
    return $out;
}

function edit_tender_form_trigger(array $t): string
{
    return '<a class="btn" href="' . e(app_url('/tenders/' . $t['id'] . '?edit=1')) . '">Edit</a>';
}

function boq_add_form(int $id): string
{
    return '<form method="post" action="' . e(app_url('/tenders/' . $id . '/boq')) . '" class="actions" style="display:inline-flex;flex-wrap:wrap">' . csrf_field()
        . '<input name="item_no" placeholder="Item no" style="width:70px">'
        . '<input name="description" placeholder="Description" required>'
        . '<input name="unit" placeholder="unit" style="width:70px">'
        . '<input name="quantity" placeholder="qty" style="width:80px" type="number" step="0.01">'
        . '<input name="estimated_rate" placeholder="rate ₹" style="width:110px" type="number" step="0.01">'
        . '<button class="btn btn-small">Add item</button></form>';
}

function bidder_add_form(int $id): string
{
    $opts = [];
    foreach (DB::all("SELECT id, legal_name, contractor_code FROM contractors WHERE status != 'debarred' ORDER BY legal_name") as $c) {
        $opts[$c['id']] = $c['legal_name'] . ' (' . $c['contractor_code'] . ')';
    }
    return '<form method="post" action="' . e(app_url('/tenders/' . $id . '/bidders')) . '" class="actions" style="flex-wrap:wrap">' . csrf_field()
        . h_select('Contractor', 'contractor_id', $opts, null, true)
        . '<input name="emd_paid_minor" placeholder="EMD ₹" type="number" step="0.01" style="width:120px">'
        . '<button class="btn btn-small">Add bidder</button></form>';
}

function evaluation_forms_html(int $id, array $bidders): string
{
    $criteria = criteria_list($id);
    $out = '<div class="card"><h2>Technical evaluation</h2>';
    $out .= h_form_open('/tenders/' . $id . '/criteria', 'post')
        . '<div class="actions">' . csrf_field()
        . '<input name="code" placeholder="Code (e.g. REG)">'
        . '<input name="criterion" placeholder="Criterion" required>'
        . '<button class="btn btn-small">Add criterion</button></div></form>';
    $out .= h_table(['Criterion', 'Requirement'], array_map(fn($c) => [e($c['code']) . ' — ' . e($c['criterion']), e($c['requirement'] ?: '')], $criteria));
    if ($criteria) {
        $out .= '<h3>Record result</h3>';
        foreach ($bidders as $b) {
            $out .= h_form_open('/tenders/' . $id . '/evaluation', 'post') . '<div class="actions">' . csrf_field()
                . '<strong>' . e($b['legal_name']) . '</strong>'
                . '<input type="hidden" name="bidder_id" value="' . (int) $b['id'] . '">'
                . '<select name="criterion_id">';
            foreach ($criteria as $c) {
                $out .= '<option value="' . (int) $c['id'] . '">' . e($c['criterion']) . '</option>';
            }
            $out .= '</select><select name="result">'
                . '<option value="pass">Pass</option><option value="fail">Fail</option><option value="clarification_required">Clarification required</option><option value="verification_required">Verification required</option><option value="not_applicable">Not applicable</option>'
                . '</select><input name="remarks" placeholder="remarks">'
                . '<button class="btn btn-small">Save</button></div></form>';
        }
        $out .= '<h3>Finalise</h3>' . h_form_open('/tenders/' . $id . '/finalize', 'post') . csrf_field()
            . '<p class="muted">Enter rejection reasons for disqualified bidders (bidder id = reason):</p>';
        foreach ($bidders as $b) {
            $out .= '<label class="field"><span class="field-label">' . e($b['legal_name']) . '</span><input name="rejection_reasons[' . (int) $b['id'] . ']" placeholder="reason (if disqualified)"></label>';
        }
        $out .= h_submit('Finalise technical evaluation', 'btn-primary') . '</form>';
    }
    $out .= '</div>';
    return $out;
}

function financial_forms_html(int $id, array $bidders): string
{
    $out = '<div class="card"><h2>Financial evaluation</h2>';
    foreach ($bidders as $b) {
        if ($b['bid_status'] !== 'technically_qualified' && $b['bid_status'] !== 'financial_opened') continue;
        $out .= h_form_open('/tenders/' . $id . '/financial-bid', 'post') . '<div class="actions">' . csrf_field()
            . '<strong>' . e($b['legal_name']) . '</strong>'
            . '<input type="hidden" name="bidder_id" value="' . (int) $b['id'] . '">'
            . '<input name="total_amount" placeholder="Total ₹" type="number" step="0.01" required>'
            . '<button class="btn btn-small">Record financial bid</button></div></form>';
    }
    $out .= h_form_open('/tenders/' . $id . '/rank', 'post') . csrf_field() . h_submit('Compute rankings (L1/L2/L3)', 'btn-primary') . '</form>';
    $out .= '<p><a href="' . e(app_url('/tenders/' . $id . '/comparative')) . '">View comparative statement</a></p></div>';
    return $out;
}

function award_form_html(int $id, array $bidders): string
{
    $opts = [];
    foreach ($bidders as $b) {
        if (in_array($b['bid_status'], ['technically_qualified', 'financial_opened'], true)) {
            $opts[$b['contractor_id']] = $b['legal_name'] . ($b['rank'] !== null ? ' (#' . (int) $b['rank'] . ')' : '');
        }
    }
    return h_form_open('/tenders/' . $id . '/award', 'post')
        . h_select('Contractor (L1)', 'contractor_id', $opts, null, true)
        . h_input('Awarded amount (₹)', 'awarded_amount', '', 'number', true)
        . h_textarea('Remarks', 'remarks', '', 2)
        . h_submit('Recommend award', 'btn-primary') . '</form>';
}

function agreement_form(array $award): string
{
    return '<form method="post" action="' . e(app_url('/awards/' . $award['id'] . '/agreement')) . '" class="inline">' . csrf_field()
        . '<input type="hidden" name="execution_date" value="' . e(today_iso()) . '">'
        . '<button class="btn">Execute agreement</button></form>';
}

function work_order_form(array $award): string
{
    return '<form method="post" action="' . e(app_url('/awards/' . $award['id'] . '/work-order')) . '" class="inline">' . csrf_field()
        . '<input type="hidden" name="start_date" value="' . e(today_iso()) . '">'
        . '<button class="btn btn-accent">Issue work order</button></form>';
}

function comparative_html(int $id): string
{
    $cs = comparative_statement($id);
    $rows = [];
    foreach ($cs['rows'] as $r) {
        $rows[] = [
            $r['rank'] !== null ? '#' . $r['rank'] : '—',
            e($r['bidder']),
            h_status($r['qualification'], ['submitted' => 'submitted', 'technical_opened' => 'info', 'technically_qualified' => 'approved', 'technically_disqualified' => 'technically_disqualified', 'financial_opened' => 'info', 'awarded' => 'awarded', 'closed' => 'closed']),
            '<span class="num">' . e($r['totalFmt']) . '</span>',
            $r['variation'] !== null ? '<span class="num">' . e(number_format((float) $r['variation'], 2)) . '%</span>' : '—',
        ];
    }
    return '<div class="page-head"><h1>Comparative statement — ' . e($cs['tender']['tender_number']) . '</h1>' . h_back('/tenders/' . $id) . '</div>'
        . '<p class="muted">Estimated cost: <strong>' . h_money($cs['estimatedMinor']) . '</strong>. Positive variation = above estimate.</p>'
        . h_table(['Rank', 'Bidder', 'Qualification', 'Total', 'Variation'], $rows);
}

function projects_list_html(array $user): string
{
    $rows = [];
    $scope = scope_condition('p');
    foreach (DB::all('SELECT p.*, f.label fy_label FROM projects p LEFT JOIN financial_years f ON f.id = p.fy_id WHERE p.deleted_at IS NULL' . $scope['sql'] . ' ORDER BY p.id DESC', $scope['params']) as $p) {
        $rows[] = [
            '<a href="' . e(app_url('/projects/' . $p['id'])) . '">' . e($p['work_name']) . '</a>',
            e($p['fy_label']),
            h_status($p['status'], ['planned' => 'draft', 'approved' => 'approved', 'tendered' => 'info', 'awarded' => 'awarded', 'in_progress' => 'in_progress', 'completed' => 'completed', 'closed' => 'closed', 'cancelled' => 'cancelled']),
            '<span class="num">' . h_money($p['estimate_amount_minor']) . '</span>',
            '<span class="num">' . e((string) ((float) $p['physical_progress'])) . '%</span>',
        ];
    }
    return '<div class="page-head"><h1>Projects</h1>' . (user_has_permission($user, 'project.manage') ? h_link('+ New Project', '/projects/new', 'btn-primary') : '') . '</div>'
        . h_table(['Work', 'FY', 'Status', 'Estimate', 'Progress'], $rows);
}

function project_form_html(array $user): string
{
    $fyOpts = []; foreach (fy_list() as $f) { $fyOpts[$f['id']] = $f['label']; }
    $schemeOpts = []; foreach (DB::all('SELECT id, name FROM schemes') as $s) { $schemeOpts[$s['id']] = $s['name']; }
    $fundOpts = []; foreach (DB::all('SELECT id, name FROM funds') as $f) { $fundOpts[$f['id']] = $f['name']; }
    return '<div class="card"><h1>New Project</h1>' . h_form_open('/projects/create', 'post')
        . '<div class="field-row">' . h_select('Financial year *', 'fy_id', $fyOpts, fy_current() ? fy_current()['id'] : null, true) . h_input('Project code', 'project_code') . '</div>'
        . '<div class="field-row">' . h_select('Scheme', 'scheme_id', $schemeOpts) . h_select('Fund', 'fund_id', $fundOpts) . '</div>'
        . h_input('Work name *', 'work_name', '', 'text', true)
        . h_input('Location', 'location')
        . h_textarea('Description', 'description', '', 3)
        . '<div class="field-row">' . h_input('Admin approval no', 'admin_approval_no') . h_input('Admin approval date', 'admin_approval_date', '', 'date') . '</div>'
        . h_input('Admin approval authority', 'admin_approval_authority')
        . h_input('Admin approval amount (₹)', 'admin_approval_amount', '', 'number')
        . '<div class="field-row">' . h_input('Technical sanction no', 'tech_sanction_no') . h_input('Technical sanction date', 'tech_sanction_date', '', 'date') . '</div>'
        . h_input('Technical sanction authority', 'tech_sanction_authority')
        . h_input('Technical sanction amount (₹)', 'tech_sanction_amount', '', 'number')
        . '<div class="field-row">' . h_input('Estimate amount (₹) *', 'estimate_amount', '', 'number', true) . h_input('Sanctioned amount (₹)', 'sanctioned_amount', '', 'number') . '</div>'
        . h_submit('Create Project', 'btn-primary') . '</form></div>';
}

function project_detail_html(int $id, array $user): string
{
    $p = project_get($id);
    $fy = DB::one('SELECT * FROM financial_years WHERE id = ?', [(int) $p['fy_id']]);
    $progress = DB::all('SELECT * FROM work_progress WHERE project_id = ? ORDER BY id DESC LIMIT 10', [$id]);
    $measurements = DB::all('SELECT * FROM measurements WHERE project_id = ? ORDER BY id DESC', [$id]);
    $bills = DB::all('SELECT * FROM bills WHERE project_id = ? ORDER BY id DESC', [$id]);
    $payments = DB::all('SELECT * FROM payments WHERE project_id = ? ORDER BY id DESC', [$id]);
    $completion = DB::one('SELECT * FROM completions WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    $extensions = DB::all('SELECT * FROM extension_requests WHERE project_id = ? ORDER BY id DESC', [$id]);

    $out = '<div class="page-head"><h1>' . e($p['work_name']) . '</h1>' . h_back('/projects') . '</div>';

    $actions = '<div class="card"><h2>Actions</h2><div class="actions">';
    if (user_has_permission($user, 'execution.manage')) {
        $actions .= progress_form($id);
    }
    if (user_has_permission($user, 'measurement.manage')) {
        $actions .= '<form method="post" action="' . e(app_url('/projects/' . $id . '/measurement')) . '" class="inline">' . csrf_field() . '<input type="hidden" name="measurement_date" value="' . e(today_iso()) . '"><button class="btn">New measurement</button></form>';
    }
    if (user_has_permission($user, 'bill.manage')) {
        $actions .= '<form method="post" action="' . e(app_url('/projects/' . $id . '/bill')) . '" class="inline">' . csrf_field() . '<input type="hidden" name="bill_type" value="running"><button class="btn">New running bill</button></form>';
    }
    if (user_has_permission($user, 'completion.manage')) {
        $actions .= completion_form($id);
    }
    $actions .= '</div></div>';

    $info = '<div class="card"><h2>Details</h2><dl class="dl">'
        . dt_dd('Financial Year', $fy ? $fy['label'] : '—')
        . dt_dd('Status', h_status($p['status'], ['planned' => 'draft', 'approved' => 'approved', 'tendered' => 'info', 'awarded' => 'awarded', 'in_progress' => 'in_progress', 'completed' => 'completed', 'closed' => 'closed', 'cancelled' => 'cancelled']))
        . dt_dd('Location', $p['location'])
        . dt_dd('Estimate', h_money($p['estimate_amount_minor']))
        . dt_dd('Sanctioned', h_money($p['sanctioned_amount_minor']))
        . dt_dd('Awarded', h_money($p['awarded_amount_minor']))
        . dt_dd('Physical progress', (string) ((float) $p['physical_progress']) . '%')
        . dt_dd('Financial progress', (string) ((float) $p['financial_progress']) . '%')
        . '</dl></div>';

    $measRows = [];
    foreach ($measurements as $m) {
        $items = DB::all('SELECT * FROM measurement_items WHERE measurement_id = ?', [(int) $m['id']]);
        $total = 0; foreach ($items as $it) { $total += (int) $it['amount_minor']; }
        $measRows[] = ['<a href="#m' . (int) $m['id'] . '">' . e($m['measurement_number']) . '</a>', h_date($m['measurement_date']), h_status($m['status'], ['draft' => 'draft', 'checked' => 'checked', 'approved' => 'approved', 'final' => 'final']), (string) count($items), '<span class="num">' . h_money($total) . '</span>'];
    }
    $measHtml = '<div class="card"><h2>Measurements</h2>' . h_table(['Number', 'Date', 'Status', 'Items', 'Amount'], $measRows);
    foreach ($measurements as $m) {
        $measHtml .= '<h3 id="m' . (int) $m['id'] . '">' . e($m['measurement_number']) . ' ' . h_status($m['status'], ['draft' => 'draft', 'checked' => 'checked', 'approved' => 'approved', 'final' => 'final']) . '</h3>';
        if (user_has_permission($user, 'measurement.manage') && in_array($m['status'], ['draft', 'checked'], true)) {
            $measHtml .= measurement_item_form($m, $p);
        }
        if (user_has_permission($user, 'measurement.approve') && in_array($m['status'], ['draft', 'checked', 'approved'], true)) {
            $measHtml .= '<div class="actions">';
            foreach (['checked', 'approved', 'final'] as $s) {
                $measHtml .= '<form method="post" action="' . e(app_url('/measurements/' . $m['id'] . '/status')) . '" class="inline">' . csrf_field() . '<input type="hidden" name="status" value="' . $s . '"><button class="btn btn-small">Mark ' . $s . '</button></form>';
            }
            $measHtml .= '</div>';
        }
        $itRows = [];
        foreach (DB::all('SELECT * FROM measurement_items WHERE measurement_id = ?', [(int) $m['id']]) as $it) {
            $itRows[] = [e($it['item_no']), e($it['description']), '<span class="num">' . e(h_qty($it['current_quantity'])) . '</span>', '<span class="num">' . h_money($it['rate_minor']) . '</span>', '<span class="num">' . h_money($it['amount_minor']) . '</span>', $it['overrun_flag'] ? '<span class="badge badge-warning">OVER RUN</span>' : ''];
        }
        $measHtml .= h_table(['Item', 'Description', 'Qty', 'Rate', 'Amount', 'Flag'], $itRows);
    }
    $measHtml .= '</div>';

    $billRows = [];
    foreach ($bills as $b) {
        $billRows[] = ['<a href="#b' . (int) $b['id'] . '">' . e($b['bill_number']) . '</a>', h_date($b['bill_date']), h_status($b['status'], ['draft' => 'draft', 'submitted' => 'submitted', 'checked' => 'checked', 'certified' => 'certified', 'approved' => 'approved', 'paid' => 'paid', 'partially_paid' => 'partially_paid', 'rejected' => 'rejected', 'returned' => 'info']), '<span class="num">' . h_money($b['net_payable_minor']) . '</span>'];
    }
    $billHtml = '<div class="card"><h2>Bills</h2>' . h_table(['Number', 'Date', 'Status', 'Net payable'], $billRows);
    foreach ($bills as $b) {
        $billHtml .= '<h3 id="b' . (int) $b['id'] . '">' . e($b['bill_number']) . ' ' . h_status($b['status'], ['draft' => 'draft', 'submitted' => 'submitted', 'checked' => 'checked', 'certified' => 'certified', 'approved' => 'approved', 'paid' => 'paid', 'partially_paid' => 'partially_paid', 'rejected' => 'rejected', 'returned' => 'info']) . '</h3>';
        if (user_has_permission($user, 'bill.manage')) {
            $billHtml .= '<form method="post" action="' . e(app_url('/bills/' . $b['id'] . '/pdf')) . '" class="inline">' . csrf_field() . '<button class="btn btn-small">Generate bill PDF</button></form>';
        }
        if ($b['status'] === 'draft' && user_has_permission($user, 'bill.manage')) {
            $billHtml .= bill_edit_form($b);
            $billHtml .= '<form method="post" action="' . e(app_url('/bills/' . $b['id'] . '/submit')) . '" class="inline">' . csrf_field() . '<button class="btn btn-primary">Submit bill</button></form>';
        }
        if (in_array($b['status'], ['submitted', 'checked', 'certified', 'returned'], true) && user_has_permission($user, 'bill.approve')) {
            $billHtml .= '<div class="actions">' . bill_workflow_buttons($b) . '</div>';
        }
        if (in_array($b['status'], ['certified', 'approved'], true) && user_has_permission($user, 'payment.manage')) {
            $billHtml .= payment_form($b);
        }
        $billHtml .= '<dl class="dl">'
            . dt_dd('Gross work value', h_money($b['gross_work_value_minor']))
            . dt_dd('Previous certified', h_money($b['previous_certified_minor']))
            . dt_dd('Current bill', h_money($b['current_bill_minor']))
            . dt_dd('Retention', h_money($b['retention_minor']))
            . dt_dd('Deductions', h_money($b['deductions_minor']))
            . dt_dd('Net payable', h_money($b['net_payable_minor']))
            . '</dl>';
    }
    $billHtml .= '</div>';

    $payRows = [];
    foreach ($payments as $pay) {
        $payRows[] = [e($pay['voucher_no']), h_date($pay['payment_date']), h_status($pay['status'], ['recorded' => 'recorded', 'approved' => 'approved', 'cancelled' => 'cancelled']), '<span class="num">' . h_money($pay['net_amount_minor']) . '</span>', e($pay['payment_method'] ?: '—')];
    }
    $payHtml = '<div class="card"><h2>Payments</h2>' . h_table(['Voucher', 'Date', 'Status', 'Net', 'Method'], $payRows) . '</div>';

    $compHtml = '';
    if ($completion) {
        $cert = $completion['certificate_document_id'] ? DB::one('SELECT * FROM documents WHERE id = ?', [(int) $completion['certificate_document_id']]) : null;
        $compHtml = '<div class="card"><h2>Completion</h2><dl class="dl">'
            . dt_dd('Status', h_status($completion['status'], ['in_progress' => 'in_progress', 'inspection_done' => 'approved', 'final_measurement_done' => 'approved', 'final_bill_done' => 'approved', 'final_payment_done' => 'approved', 'security_released' => 'approved', 'closed' => 'closed']))
            . dt_dd('Completion date', h_date($completion['completion_date']))
            . dt_dd('Handover date', h_date($completion['handover_date']))
            . ($cert ? dt_dd('Certificate', '<a href="' . e(app_url('/documents/' . $cert['id'])) . '">View certificate</a>') : '')
            . '</dl></div>';
    }

    $progRows = [];
    foreach ($progress as $pr) {
        $progRows[] = [h_date($pr['progress_date']), (string) ((float) $pr['physical_progress']) . '%', (string) ((float) $pr['financial_progress']) . '%', e($pr['notes'] ?: '')];
    }
    $progHtml = '<div class="card"><h2>Progress history</h2>' . h_table(['Date', 'Physical', 'Financial', 'Notes'], $progRows) . '</div>';

    return $out . $actions . $info . $measHtml . $billHtml . $payHtml . $compHtml . $progHtml;
}

function progress_form(int $id): string
{
    return '<form method="post" action="' . e(app_url('/projects/' . $id . '/progress')) . '" class="actions" style="flex-wrap:wrap">' . csrf_field()
        . '<input type="hidden" name="progress_date" value="' . e(today_iso()) . '">'
        . '<input name="physical_progress" placeholder="physical %" type="number" step="0.1" style="width:110px">'
        . '<input name="financial_progress" placeholder="financial %" type="number" step="0.1" style="width:110px">'
        . '<input name="notes" placeholder="notes">'
        . '<button class="btn btn-small">Record progress</button></form>';
}

function measurement_item_form(array $m, array $p): string
{
    $boqOpts = [];
    $tender = DB::one('SELECT * FROM tenders WHERE project_id = ? ORDER BY id DESC LIMIT 1', [(int) $p['id']]);
    if ($tender) {
        foreach (DB::all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [(int) $tender['id']]) as $b) {
            $boqOpts[$b['id']] = $b['item_no'] . ' — ' . $b['description'];
        }
    }
    return '<form method="post" action="' . e(app_url('/measurements/' . $m['id'] . '/item')) . '" class="actions" style="flex-wrap:wrap">' . csrf_field()
        . h_select('BOQ item', 'boq_item_id', $boqOpts, null)
        . '<input name="item_no" placeholder="item no" style="width:70px">'
        . '<input name="description" placeholder="description" required>'
        . '<input name="unit" placeholder="unit" style="width:70px">'
        . '<input name="previous_quantity" placeholder="prev qty" type="number" step="0.01" style="width:90px">'
        . '<input name="current_quantity" placeholder="current qty" type="number" step="0.01" style="width:100px" required>'
        . '<input name="rate" placeholder="rate ₹" type="number" step="0.01" style="width:100px" required>'
        . '<button class="btn btn-small">Add item</button></form>';
}

function bill_edit_form(array $b): string
{
    return h_form_open('/bills/' . $b['id'] . '/update', 'post')
        . '<div class="field-row">'
        . h_input('Gross work value (₹)', 'gross_work_value', from_minor($b['gross_work_value_minor']), 'number')
        . h_input('Previous certified (₹)', 'previous_certified', from_minor($b['previous_certified_minor']), 'number')
        . '</div><div class="field-row">'
        . h_input('Retention %', 'retention_pct', '0', 'number')
        . h_input('Tax amount (₹)', 'tax_amount', from_minor($b['tax_minor']), 'number')
        . '</div><div class="field-row">'
        . h_input('Deductions (₹)', 'deductions', from_minor($b['deductions_minor']), 'number')
        . h_input('Recoveries (₹)', 'recoveries', from_minor($b['recoveries_minor']), 'number')
        . '</div>'
        . h_submit('Save bill', 'btn-primary') . '</form>';
}

function bill_workflow_buttons(array $b): string
{
    $out = '';
    foreach ([['approve', 'Approve', 'btn-primary'], ['return', 'Return', 'btn-outline'], ['reject', 'Reject', 'btn-danger']] as [$action, $label, $cls]) {
        $out .= '<form method="post" action="' . e(app_url('/bills/' . $b['id'] . '/workflow')) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="action" value="' . $action . '"><button class="btn btn-small ' . $cls . '">' . $label . '</button></form>';
    }
    return $out;
}

function payment_form(array $b): string
{
    return h_form_open('/bills/' . $b['id'] . '/payment', 'post')
        . '<div class="field-row">'
        . h_input('Net amount (₹)', 'net_amount', from_minor($b['net_payable_minor']), 'number', true)
        . h_input('Payment date', 'payment_date', today_iso(), 'date')
        . '</div><div class="field-row">'
        . h_select('Method', 'payment_method', ['bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'cash' => 'Cash'], 'bank_transfer')
        . h_input('Transaction reference', 'transaction_reference')
        . '</div>'
        . '<p class="muted">Payments are recorded in the system, not executed. This portal does not integrate with a bank gateway.</p>'
        . h_submit('Record payment', 'btn-primary') . '</form>';
}

function completion_form(int $id): string
{
    return '<form method="post" action="' . e(app_url('/projects/' . $id . '/complete')) . '" class="actions">' . csrf_field()
        . '<select name="status">'
        . '<option value="inspection_done">Inspection done</option>'
        . '<option value="final_measurement_done">Final measurement done</option>'
        . '<option value="final_bill_done">Final bill done</option>'
        . '<option value="final_payment_done">Final payment done</option>'
        . '<option value="security_released">Security released</option>'
        . '<option value="closed">Close project</option>'
        . '</select>'
        . '<button class="btn btn-small">Advance completion</button></form>';
}

function contractors_list_html(array $user): string
{
    $rows = [];
    $scope = scope_condition();
    foreach (DB::all('SELECT * FROM contractors WHERE deleted_at IS NULL' . $scope['sql'] . ' ORDER BY id DESC', $scope['params']) as $c) {
        $exp = DB::val('SELECT expiry_date FROM contractor_documents WHERE contractor_id = ? AND expiry_status != ? ORDER BY expiry_date LIMIT 1', [(int) $c['id'], 'not_applicable']);
        $rows[] = [
            '<a href="' . e(app_url('/contractors/' . $c['id'])) . '">' . e($c['legal_name']) . '</a>',
            e($c['contractor_code']),
            e($c['registration_class'] ?: '—'),
            h_status($c['status'], ['active' => 'active', 'inactive' => 'info', 'debarred' => 'debarred']),
            $exp ? h_date($exp) : '—',
        ];
    }
    return '<div class="page-head"><h1>Contractors</h1>' . (user_has_permission($user, 'contractor.manage') ? h_link('+ New Contractor', '/contractors/new', 'btn-primary') : '') . '</div>'
        . h_table(['Name', 'Code', 'Class', 'Status', 'Next expiry'], $rows);
}

function contractor_form_html(): string
{
    return '<div class="card"><h1>New Contractor</h1>' . h_form_open('/contractors/create', 'post')
        . h_input('Legal name *', 'legal_name', '', 'text', true)
        . h_input('Business name', 'business_name')
        . h_textarea('Address', 'address', '', 2)
        . '<div class="field-row">' . h_input('Mobile', 'mobile') . h_input('Email', 'email', '', 'email') . '</div>'
        . '<div class="field-row">' . h_input('Registration no', 'registration_no') . h_input('Registration class', 'registration_class') . '</div>'
        . '<div class="field-row">' . h_input('Registration valid from', 'registration_valid_from', '', 'date') . h_input('Registration valid to', 'registration_valid_to', '', 'date') . '</div>'
        . '<div class="field-row">' . h_input('PAN', 'pan') . h_input('GST', 'gst') . '</div>'
        . '<div class="field-row">' . h_input('Bank name', 'bank_name') . h_input('Account no', 'bank_account_no') . '</div>'
        . h_input('IFSC', 'bank_ifsc')
        . h_textarea('Experience summary', 'experience_summary', '', 3)
        . h_submit('Create Contractor', 'btn-primary') . '</form></div>';
}

function contractor_detail_html(int $id, array $user): string
{
    $c = contractor_get($id);
    $docs = DB::all('SELECT * FROM contractor_documents WHERE contractor_id = ? ORDER BY id DESC', [$id]);
    $sensitive = user_has_permission($user, 'contractor.sensitive');
    $out = '<div class="page-head"><h1>' . e($c['legal_name']) . '</h1>' . h_back('/contractors') . '</div>'
        . '<div class="card"><h2>Details</h2><dl class="dl">'
        . dt_dd('Code', $c['contractor_code'])
        . dt_dd('Business name', $c['business_name'] ?: '—')
        . dt_dd('Status', h_status($c['status'], ['active' => 'active', 'inactive' => 'info', 'debarred' => 'debarred']))
        . dt_dd('Registration', ($c['registration_no'] ? e($c['registration_no']) . ' (' . e($c['registration_class'] ?: '') . ')' : '—'))
        . dt_dd('Registration validity', h_date($c['registration_valid_from']) . ' → ' . h_date($c['registration_valid_to']))
        . ($sensitive ? dt_dd('PAN', $c['pan'] ?: '—') . dt_dd('GST', $c['gst'] ?: '—') . dt_dd('Bank', ($c['bank_name'] ? e($c['bank_name']) . ' · ' . e($c['bank_account_no'] ?: '') . ' · ' . e($c['bank_ifsc'] ?: '') : '—')) : dt_dd('Sensitive info', '<em>Restricted (contractor.sensitive permission)</em>'))
        . dt_dd('Experience', $c['experience_summary'] ?: '—')
        . '</dl></div>';

    $docRows = [];
    foreach ($docs as $d) {
        $docRows[] = [e($d['doc_type']), e($d['doc_name']), h_date($d['issued_date']), h_date($d['expiry_date']), h_status($d['expiry_status'], ['valid' => 'valid', 'expiring_soon' => 'expiring_soon', 'expired' => 'expired', 'verification_required' => 'verification_required', 'not_applicable' => 'info'])];
    }
    $out .= '<div class="card"><h2>Documents & expiry tracking</h2>'
        . h_form_open('/contractors/' . $id . '/document', 'post')
        . '<div class="field-row">' . h_input('Document type', 'doc_type', 'registration') . h_input('Document name', 'doc_name', 'Registration certificate') . '</div>'
        . '<div class="field-row">' . h_input('Issued', 'issued_date', '', 'date') . h_input('Expiry', 'expiry_date', '', 'date') . '</div>'
        . h_submit('Add document', 'btn-primary') . '</form>'
        . h_table(['Type', 'Name', 'Issued', 'Expiry', 'Status'], $docRows) . '</div>';
    return $out;
}

function rules_html(): string
{
    $rulesets = DB::all('SELECT * FROM rulesets ORDER BY id');
    $out = '<div class="page-head"><h1>Rules & References</h1></div>';
    foreach ($rulesets as $rs) {
        $rules = compliance_get_rules((int) $rs['id']);
        $rows = [];
        foreach ($rules as $r) {
            $ref = compliance_ref_label($r['reference_id'] !== null ? (int) $r['reference_id'] : null);
            $rows[] = [
                e($r['code']),
                e($r['title']),
                '<span class="verification">' . e(SEVERITY_LABELS[$r['severity']] ?? $r['severity']) . '</span>',
                e($r['rule_type']),
                $ref ? e($ref['title']) . '<br><small class="muted">' . e($ref['reference_number'] ?: '') . '</small>' : '—',
            ];
        }
        $out .= '<div class="card"><h2>' . e($rs['name']) . ' <span class="muted">(v' . (int) $rs['version'] . ')</span></h2>'
            . '<p class="muted">' . e($rs['notes']) . '</p>'
            . h_table(['Code', 'Rule', 'Severity', 'Type', 'Source'], $rows) . '</div>';
    }
    $refRows = [];
    foreach (DB::all('SELECT * FROM rule_references ORDER BY id') as $r) {
        $refRows[] = [e($r['title']), e($r['authority']), e($r['reference_number'] ?: '—'), '<a href="' . e($r['source_url']) . '" target="_blank" rel="noopener">source</a>', e($r['version'] ?: '')];
    }
    $out .= '<div class="card"><h2>Authoritative references</h2>'
        . '<p class="muted">Thresholds and notice periods are configurable and sourced from published WB government circulars. Where a rule is uncertain, the engine reports "Verification Required". This system does not provide legal advice.</p>'
        . h_table(['Reference', 'Authority', 'Reference no.', 'Source', 'Version'], $refRows) . '</div>';
    return $out;
}

function compliance_list_html(): string
{
    $rows = [];
    $scope = scope_condition('t');
    foreach (DB::all('SELECT t.id, t.tender_number, t.title, t.work_name, t.compliance_status, t.status FROM tenders t WHERE t.deleted_at IS NULL' . $scope['sql'] . ' ORDER BY t.id DESC', $scope['params']) as $t) {
        $rows[] = [
            '<a href="' . e(app_url('/compliance/' . $t['id'])) . '">' . e($t['tender_number']) . '</a>',
            e($t['work_name'] ?: $t['title']),
            $t['compliance_status'] ? h_status($t['compliance_status'], ['passed' => 'approved', 'warning' => 'warning', 'blocking' => 'blocking', 'verification_required' => 'verification_required']) : '<span class="muted">not evaluated</span>',
            h_status($t['status'], ['draft' => 'draft', 'under_approval' => 'under_approval', 'approved' => 'approved', 'published' => 'published', 'awarded' => 'awarded', 'closed' => 'closed', 'cancelled' => 'cancelled']),
        ];
    }
    return '<div class="page-head"><h1>Compliance</h1></div>'
        . '<div class="card"><p class="muted">Compliance checks run against the configured rule set. A "passed" result means the record passes the configured system checks — it is not a claim of legal compliance.</p></div>'
        . h_table(['Tender', 'Work', 'Compliance', 'Tender status'], $rows);
}

function compliance_detail_html(int $id, array $user): string
{
    $t = tender_get($id);
    $latest = compliance_get_latest('tender', $id);
    $out = '<div class="page-head"><h1>Compliance — ' . e($t['tender_number']) . '</h1>' . h_back('/compliance') . '</div>';
    if (user_has_permission($user, 'rules.view')) {
        $out .= '<div class="card"><form method="post" action="' . e(app_url('/tenders/' . $id . '/compliance')) . '">' . csrf_field() . h_submit('Re-run compliance check', 'btn-primary') . '</form></div>';
    }
    if (!$latest) {
        return $out . '<div class="card"><p class="muted">Not evaluated yet.</p></div>';
    }
    $rows = [];
    foreach ($latest['findings'] as $f) {
        $rows[] = [
            '<span class="verification">' . e($f['severityLabel']) . '</span>',
            e($f['ruleCode']),
            e($f['ruleTitle']),
            e($f['message']),
            $f['source'] ? e($f['source']['title'] ?? '') . '<br><small class="muted">' . e($f['source']['reference_number'] ?? '') . '</small>' : '—',
        ];
    }
    $out .= '<div class="card"><h2>Findings (' . e($latest['summary']['passed'] ?? '0') . ' passed · ' . e($latest['summary']['blocking'] ?? '0') . ' blocking · ' . e($latest['summary']['verification_required'] ?? '0') . ' verification required · ' . e($latest['summary']['not_applicable'] ?? '0') . ' not applicable)</h2>'
        . h_table(['Severity', 'Code', 'Rule', 'Result', 'Source'], $rows) . '</div>';
    return $out;
}

function reports_html(): string
{
    $fyId = (int) ($_GET['fy_id'] ?? 0) ?: (fy_current() ? (int) fy_current()['id'] : 0);
    $d = report_dashboard($fyId ?: null);
    $rows = [];
    foreach (($d['statusPipeline'] ?? []) as $r) {
        $rows[] = [e($r['status']), (int) $r['c']];
    }
    $out = '<div class="page-head"><h1>Reports</h1>' . fy_filter_form($fyId ?: null) . '</div>';
    $out .= '<div class="grid grid-3">'
        . stat_card(inr($d['tenders']['tender_value'] ?? 0), 'Tender value')
        . stat_card(inr($d['awards']['awardedMinor'] ?? 0), 'Awarded value')
        . stat_card(inr($d['payments']['paidMinor'] ?? 0), 'Payments recorded')
        . '</div>';
    $out .= '<div class="card"><h2>Export</h2><p class="muted">CSV and PDF exports are generated from live database records with current filters.</p>'
        . '<div class="actions">' . h_link('Reconciliation report', '/reports/reconciliation?fy_id=' . $fyId, 'btn-outline')
        . h_link('Export tenders CSV', '/reports/tenders-csv?fy_id=' . $fyId, 'btn-outline')
        . h_link('Export tenders PDF', '/reports/tenders-pdf?fy_id=' . $fyId, 'btn-outline') . '</div></div>';
    return $out;
}

function reconciliation_html(): string
{
    $fyId = (int) ($_GET['fy_id'] ?? 0) ?: null;
    $rows = [];
    foreach (report_reconciliation($fyId) as $r) {
        $rows[] = [
            e($r['tender_number']),
            e($r['title'] ?: ''),
            e($r['fy_label']),
            '<span class="num">' . h_money($r['estimated_cost_minor']) . '</span>',
            '<span class="num">' . h_money($r['awarded_minor']) . '</span>',
            '<span class="num">' . h_money($r['certified_minor']) . '</span>',
            '<span class="num">' . h_money($r['paid_minor']) . '</span>',
        ];
    }
    return '<div class="page-head"><h1>Reconciliation</h1>' . h_back('/reports') . '</div>'
        . '<p class="muted">Estimated → awarded → certified → paid, per tender.</p>'
        . h_table(['Tender', 'Work', 'FY', 'Estimated', 'Awarded', 'Certified', 'Paid'], $rows);
}

function fy_html(array $user): string
{
    $rows = [];
    foreach (fy_list() as $f) {
        $actions = '';
        if ($f['status'] === 'open' && user_has_permission($user, 'fy.manage')) {
            $actions = '<form method="post" action="' . e(app_url('/financial-years/' . $f['id'] . '/close')) . '" class="inline" data-confirm="Close this financial year?">' . csrf_field() . '<button class="btn btn-small">Close</button></form>';
        }
        $rows[] = [e($f['label']), h_date($f['start_date']), h_date($f['end_date']), h_status($f['status'], ['open' => 'open', 'closed' => 'closed']), (int) $f['is_current'] === 1 ? '<span class="badge badge-approved">CURRENT</span>' : '', $actions];
    }
    $form = user_has_permission($user, 'fy.manage')
        ? '<div class="card"><h2>Add financial year</h2>' . h_form_open('/financial-years/create', 'post') . h_input('Label (YYYY-YY)', 'label', '', 'text', true) . h_submit('Add', 'btn-primary') . '</form></div>'
        : '';
    return '<div class="page-head"><h1>Financial Years</h1></div>' . $form
        . h_table(['Label', 'Start', 'End', 'Status', 'Current', 'Actions'], $rows);
}

function users_html(array $user): string
{
    $rows = [];
    $scope = scope_condition();
    foreach (DB::all('SELECT * FROM users WHERE deleted_at IS NULL' . $scope['sql'] . ' ORDER BY id', $scope['params']) as $u) {
        $roles = array_map(fn($r) => $r['name'], user_roles((int) $u['id']));
        $rows[] = [e($u['name']), e($u['email']), e(implode(', ', $roles)), e($u['designation'] ?: '—'), (int) $u['is_active'] === 1 ? '<span class="badge badge-active">active</span>' : '<span class="badge badge-cancelled">disabled</span>'];
    }
    $roleSql = (int) ($user['is_global_admin'] ?? 0) === 1 ? 'SELECT id, name FROM roles ORDER BY name' : "SELECT id, name FROM roles WHERE code != 'super_admin' ORDER BY name";
    $roleOpts = []; foreach (DB::all($roleSql) as $r) { $roleOpts[$r['id']] = $r['name']; }
    $panOpts = [];
    if ((int) ($user['is_global_admin'] ?? 0) === 1) {
        foreach (DB::all('SELECT id, gram_panchayat FROM panchayats ORDER BY gram_panchayat') as $p) { $panOpts[$p['id']] = $p['gram_panchayat']; }
    } else {
        $pid = actor_panchayat_id($user);
        foreach (DB::all('SELECT id, gram_panchayat FROM panchayats WHERE id = ?', [$pid]) as $p) { $panOpts[$p['id']] = $p['gram_panchayat']; }
    }
    $form = user_has_permission($user, 'user.manage')
        ? '<div class="card"><h2>Create user</h2>' . h_form_open('/users/create', 'post')
        . '<div class="field-row">' . h_input('Name *', 'name', '', 'text', true) . h_input('Email *', 'email', '', 'email', true) . '</div>'
        . '<div class="field-row">' . h_select('Role *', 'role_id', $roleOpts, null, true) . h_select('Panchayat', 'panchayat_id', $panOpts, null, false) . '</div>'
        . '<div class="field-row">' . h_input('Designation', 'designation') . h_input('Password *', 'password', '', 'password', true, 'Minimum 10 characters including upper-case, lower-case and number.') . '</div><p class="muted">Panchayat is required for all non-global users. Choosing Super Admin creates an unscoped global administrator.</p>'
        . h_submit('Create user', 'btn-primary') . '</form></div>'
        : '';
    return '<div class="page-head"><h1>Users & Roles</h1></div>' . $form . h_table(['Name', 'Email', 'Roles', 'Designation', 'Status'], $rows);
}

function audit_html(): string
{
    $rows = [];
    $scope = scope_condition();
    foreach (DB::all('SELECT * FROM audit_logs WHERE 1=1' . $scope['sql'] . ' ORDER BY id DESC LIMIT 300', $scope['params']) as $a) {
        $rows[] = [h_date($a['created_at']), e($a['action']), e($a['entity_type'] . ($a['entity_id'] ? '#' . $a['entity_id'] : '')), e($a['actor_name'] ?: 'system'), e($a['ip_address'] ?: '')];
    }
    return '<div class="page-head"><h1>Audit Log</h1></div><div class="card"><p class="muted">Append-only audit trail. Recent 300 entries.</p></div>'
        . h_table(['When', 'Action', 'Entity', 'Actor', 'IP'], $rows);
}

function notifications_html(array $user): string
{
    $rows = [];
    foreach (DB::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [(int) $user['id']]) as $n) {
        $rows[] = [(int) $n['is_read'] === 1 ? '' : '<strong>●</strong>', e($n['title']), e($n['body'] ?: ''), h_date($n['created_at'])];
    }
    return '<div class="page-head"><h1>Notifications</h1>'
        . '<form method="post" action="' . e(app_url('/notifications/read')) . '">' . csrf_field() . '<button class="btn btn-small">Mark all read</button></form></div>'
        . h_table(['', 'Title', 'Body', 'When'], $rows);
}

function settings_html(): string
{
    $settings = [];
    foreach (DB::all('SELECT * FROM settings WHERE ' . sql_ident('key') . " LIKE 'numbering.%' OR " . sql_ident('key') . " = 'contractor.expiry_warning_days'") as $s) {
        $settings[$s['key']] = $s['value'];
    }
    return '<div class="page-head"><h1>Settings</h1></div><div class="card">'
        . h_form_open('/settings', 'post')
        . h_input('Numbering prefix', 'numbering.prefix', $settings['numbering.prefix'] ?? 'GP')
        . h_input('Tender/NIT pattern', 'numbering.tender_nit.pattern', $settings['numbering.tender_nit.pattern'] ?? '{PREFIX}/NIT/{FY}/{NNN}', 'text', false, 'Placeholders: {PREFIX}, {FY}, {NNN}, {N}')
        . h_input('Bill pattern', 'numbering.bill.pattern', $settings['numbering.bill.pattern'] ?? 'BILL/{FY}/{NNN}')
        . h_input('Work order pattern', 'numbering.work_order.pattern', $settings['numbering.work_order.pattern'] ?? 'WO/{FY}/{NNN}')
        . h_input('Agreement pattern', 'numbering.agreement.pattern', $settings['numbering.agreement.pattern'] ?? 'AGR/{FY}/{NNN}')
        . h_input('LOA pattern', 'numbering.loa.pattern', $settings['numbering.loa.pattern'] ?? 'LOA/{FY}/{NNN}')
        . h_input('Contractor expiry warning (days)', 'contractor.expiry_warning_days', $settings['contractor.expiry_warning_days'] ?? '60')
        . h_submit('Save settings', 'btn-primary') . '</form></div>';
}

function backups_html(array $user): string
{
    $isGlobal = (int) ($user['is_global_admin'] ?? 0) === 1;
    $content = '<div class="page-head"><h1>Backup & restore</h1>'
        . ($isGlobal ? h_form_open('/backups/create', 'post') . '<button class="btn btn-primary">Create full backup</button></form>' : '')
        . '</div>';
    if (!$isGlobal) {
        return $content . '<div class="card"><div class="alert alert-danger">Full database backup/restore is restricted to global administrators to prevent cross-Panchayat data exposure.</div></div>';
    }
    $content .= '<div class="card"><p class="muted">Backups are offline JSON snapshots generated from the live database and stored under <code>data/backups</code>. Restore is destructive and must be confirmed by typing RESTORE.</p></div>';
    $rows = [];
    foreach (backup_list($user) as $b) {
        $restoreForm = h_form_open('/backups/' . $b['id'] . '/restore', 'post')
            . '<input name="confirm" placeholder="Type RESTORE" autocomplete="off">'
            . '<button class="btn btn-danger" type="submit">Restore</button></form>';
        $rows[] = [
            e($b['file_name']),
            e($b['backup_type']),
            e((string) $b['size_bytes']),
            h_status($b['status'], ['created' => 'approved', 'failed' => 'rejected']),
            h_date($b['created_at']),
            e($b['created_by_name'] ?? ''),
            '<a href="' . e(app_url('/backups/' . $b['id'])) . '">Download</a>',
            $restoreForm,
        ];
    }
    return $content . h_table(['File', 'Type', 'Bytes', 'Status', 'Created', 'By', 'Download', 'Restore'], $rows);
}

function documents_list_html(array $user): string
{
    $scope = scope_condition('d');
    $docs = DB::all(
        'SELECT d.*, u.name uploaded_by_name FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE 1=1'
        . $scope['sql'] . ' ORDER BY d.id DESC LIMIT 250',
        $scope['params']
    );
    $rows = [];
    foreach ($docs as $d) {
        $entity = e($d['entity_type']) . '#' . e((string) $d['entity_id']);
        $rows[] = [
            e($d['category']),
            e($d['original_name']),
            $entity,
            e($d['mime_type'] ?: 'application/octet-stream'),
            e((string) $d['version']),
            h_date($d['created_at']),
            '<a href="' . e(app_url('/documents/' . $d['id'])) . '">Open</a>',
        ];
    }
    return '<div class="page-head"><h1>Document register</h1>'
        . (user_has_permission($user, 'document.upload') ? h_link('Upload document', '/documents/upload', 'btn-primary') : '')
        . '</div>'
        . '<div class="card"><p class="muted">Generated documents are PDF files produced from database records. Uploaded supporting documents are stored under the protected data directory with extension/MIME checks and SHA-256 hashes.</p></div>'
        . h_table(['Category', 'File', 'Entity', 'MIME type', 'Version', 'Created', 'Action'], $rows);
}

function document_upload_html(): string
{
    $types = [
        'tender' => 'Tender', 'project' => 'Project', 'contractor' => 'Contractor', 'bill' => 'Bill',
        'payment' => 'Payment', 'award' => 'Award', 'work_order' => 'Work order', 'agreement' => 'Agreement',
        'measurement' => 'Measurement', 'completion' => 'Completion',
    ];
    return '<div class="page-head"><h1>Upload supporting document</h1>' . h_back('/documents') . '</div>'
        . '<div class="card">'
        . h_form_open('/documents/upload', 'post', 'enctype="multipart/form-data"')
        . '<div class="field-row">' . h_select('Entity type', 'entity_type', $types, null, true)
        . h_input('Entity ID', 'entity_id', '', 'number', true, 'Use the ID shown in the relevant tender/project/bill URL or detail page.') . '</div>'
        . h_input('Category', 'category', 'supporting', 'text', true, 'Examples: sanction, boq, bidder_doc, measurement_photo, payment_reference.')
        . '<label class="field"><span class="field-label">File *</span><input type="file" name="document" required></label>'
        . '<p class="muted">Allowed: PDF, images, Office/OpenDocument files, CSV and text. HTML/SVG/scripts are rejected. Maximum size: ' . e((string) round(MAX_UPLOAD_BYTES / 1024 / 1024, 1)) . ' MB.</p>'
        . h_submit('Upload document', 'btn-primary') . '</form></div>';
}

function public_list_html(): string
{
    $rows = [];
    foreach (DB::all("SELECT * FROM tenders WHERE deleted_at IS NULL AND status IN ('published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','closed') ORDER BY id DESC") as $t) {
        $rows[] = [
            '<a href="' . e(app_url('/public/tenders/' . $t['id'])) . '">' . e($t['tender_number']) . '</a>',
            e($t['work_name'] ?: $t['title']),
            h_date($t['publication_date']),
            h_date($t['bid_close_date']),
            h_status($t['status'], ['published' => 'published', 'bidding' => 'bidding', 'bid_closed' => 'bid_closed', 'technical_evaluation' => 'technical_evaluation', 'financial_evaluation' => 'financial_evaluation', 'awarded' => 'awarded', 'closed' => 'closed']),
        ];
    }
    return '<div class="page-head"><h1>Public Tender Portal</h1>' . (current_user() ? h_link('Dashboard', '/', 'btn-outline') : h_link('Staff sign in', '/login', 'btn-outline')) . '</div>'
        . '<div class="card"><p class="muted">Published tender information for public view. Official publication remains on the authorised government portals (e.g. wbtenders.gov.in).</p></div>'
        . h_table(['Tender no.', 'Work', 'Published', 'Bid close', 'Status'], $rows);
}

function public_tender_html(int $id): string
{
    $t = DB::one("SELECT * FROM tenders WHERE id = ? AND deleted_at IS NULL AND status IN ('published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','closed')", [$id]);
    if (!$t) {
        throw not_found('Tender not available publicly');
    }
    $p = panchayat_context($t['panchayat_id'] !== null ? (int) $t['panchayat_id'] : null);
    $boq = DB::all('SELECT * FROM boq_items WHERE tender_id = ? ORDER BY sort_order, id', [$id]);
    $nitDoc = DB::one("SELECT d.* FROM documents d WHERE d.entity_type = 'tender' AND d.entity_id = ? AND d.category = 'nit' ORDER BY d.id DESC LIMIT 1", [$id]);
    $out = '<div class="page-head"><h1>' . e($t['tender_number']) . '</h1>' . h_back('/public') . '</div>'
        . '<div class="card"><dl class="dl">'
        . dt_dd('Panchayat', e($p['gram_panchayat'] ?? APP_NAME))
        . dt_dd('Work name', e($t['work_name'] ?: $t['title']))
        . dt_dd('Description', e($t['description'] ?: '—'))
        . dt_dd('Location', e($t['location'] ?: '—'))
        . dt_dd('Estimated cost', h_money($t['estimated_cost_minor']))
        . dt_dd('EMD', $t['emd_minor'] !== null ? h_money($t['emd_minor']) : '—')
        . dt_dd('Tender fee', $t['tender_fee_minor'] !== null ? h_money($t['tender_fee_minor']) : '—')
        . dt_dd('Published', h_date($t['publication_date']))
        . dt_dd('Bid start', h_date($t['bid_start_date']))
        . dt_dd('Bid close', h_date($t['bid_close_date']))
        . dt_dd('Technical open', h_date($t['technical_open_date']))
        . ($nitDoc ? dt_dd('NIT document', '<a href="' . e(app_url('/documents/' . $nitDoc['id'])) . '">View NIT</a>') : '')
        . '</dl></div>';
    $rows = [];
    foreach ($boq as $b) {
        $rows[] = [e($b['item_no']), e($b['description']), e($b['unit']), '<span class="num">' . e(h_qty($b['quantity'])) . '</span>', '<span class="num">' . h_money($b['estimated_rate_minor']) . '</span>'];
    }
    $out .= '<div class="card"><h2>Bill of Quantities (schedule)</h2>' . h_table(['Item', 'Description', 'Unit', 'Qty', 'Rate'], $rows) . '</div>';
    return $out;
}
