<?php
/**
 * View / layout helpers. Server-rendered HTML (no front-end framework), safe
 * against XSS by escaping every interpolated value with e().
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function flash_set(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_take(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function app_url(string $path = ''): string
{
    $base = base_path();
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

// ---------------------------------------------------------------------------
// Small HTML fragments
// ---------------------------------------------------------------------------
function h_status(string $status, array $map = []): string
{
    $cls = $map[$status] ?? 'default';
    return '<span class="badge badge-' . e($cls) . '">' . e(strtoupper(str_replace('_', ' ', $status))) . '</span>';
}

function h_money($minor): string
{
    return e(inr($minor));
}

function h_date(?string $iso): string
{
    return $iso ? e(fmt_date($iso)) : '<span class="muted">—</span>';
}

/** Format a quantity/measurement number without a locale thousand separator. */
function h_qty($q): string
{
    $f = (float) $q;
    if ($f === floor($f)) {
        return number_format($f, 0, '.', '');
    }
    return rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

function h_empty(string $msg = 'No records found'): string
{
    return '<p class="empty">' . e($msg) . '</p>';
}

function h_table(array $headers, array $rows, string $empty = 'No records found'): string
{
    if (!$rows) {
        return h_empty($empty);
    }
    $out = '<div class="table-wrap"><table class="table"><thead><tr>';
    foreach ($headers as $th) {
        $out .= '<th>' . $th . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($row as $cell) {
            $out .= '<td>' . $cell . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

function h_form_open(string $action = '', string $method = 'post', string $extraAttrs = ''): string
{
    $target = $action;
    if ($action !== '' && str_starts_with($action, '/')) {
        $target = app_url($action);
    }
    $attrs = 'action="' . e($target) . '" method="' . e($method) . '"' . ($extraAttrs !== '' ? ' ' . $extraAttrs : '');
    if (strtolower($method) === 'post') {
        return '<form ' . $attrs . ' class="form"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
    return '<form ' . $attrs . ' class="form">';
}

function h_input(string $label, string $name, $value = '', string $type = 'text', bool $required = false, string $hint = ''): string
{
    $req = $required ? ' required' : '';
    $hintHtml = $hint ? '<small class="hint">' . e($hint) . '</small>' : '';
    return '<label class="field"><span class="field-label">' . e($label) . ($required ? ' *' : '') . '</span>'
        . '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e((string) $value) . '"' . $req . '>'
        . $hintHtml . '</label>';
}

function h_textarea(string $label, string $name, $value = '', int $rows = 4): string
{
    return '<label class="field"><span class="field-label">' . e($label) . '</span>'
        . '<textarea name="' . e($name) . '" rows="' . $rows . '">' . e((string) $value) . '</textarea></label>';
}

function h_select(string $label, string $name, array $options, $selected = null, bool $required = false): string
{
    $req = $required ? ' required' : '';
    $out = '<label class="field"><span class="field-label">' . e($label) . ($required ? ' *' : '') . '</span>'
        . '<select name="' . e($name) . '"' . $req . '>';
    $out .= '<option value="">— Select —</option>';
    foreach ($options as $val => $lbl) {
        $sel = ($selected !== null && (string) $val === (string) $selected) ? ' selected' : '';
        $out .= '<option value="' . e($val) . '"' . $sel . '>' . e($lbl) . '</option>';
    }
    $out .= '</select></label>';
    return $out;
}

function h_submit(string $label = 'Save', string $cls = 'btn-primary'): string
{
    return '<div class="form-actions"><button type="submit" class="btn ' . e($cls) . '">' . e($label) . '</button></div>';
}

function h_link(string $label, string $path, string $cls = 'btn'): string
{
    return '<a class="btn ' . e($cls) . '" href="' . e(app_url($path)) . '">' . e($label) . '</a>';
}

function h_back(string $path): string
{
    return '<p class="muted"><a href="' . e(app_url($path)) . '">← Back</a></p>';
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------
function layout_nav_items(): array
{
    return [
        'dashboard' => ['/', 'Dashboard', 'report.view'],
        'panchayat' => ['/panchayat', 'Panchayat', 'panchayat.view'],
        'plans' => ['/procurement-plans', 'Procurement Plan', 'plan.view'],
        'schemes' => ['/schemes', 'Schemes', 'scheme.view'],
        'funds' => ['/funds', 'Funds', 'scheme.view'],
        'budgets' => ['/budgets', 'Budgets', 'scheme.view'],
        'tenders' => ['/tenders', 'Tenders', 'tender.view'],
        'projects' => ['/projects', 'Projects', 'project.view'],
        'contractors' => ['/contractors', 'Contractors', 'contractor.view'],
        'rules' => ['/rules', 'Rules & Refs', 'rules.view'],
        'compliance' => ['/compliance', 'Compliance', 'rules.view'],
        'reports' => ['/reports', 'Reports', 'report.view'],
        'documents' => ['/documents', 'Documents', 'document.view'],
        'fy' => ['/financial-years', 'Financial Years', 'fy.view'],
        'users' => ['/users', 'Users & Roles', 'user.view'],
        'audit' => ['/audit', 'Audit Log', 'audit.view'],
        'notifications' => ['/notifications', 'Notifications', 'notification.view'],
        'settings' => ['/settings', 'Settings', 'settings.manage'],
        'backups' => ['/backups', 'Backups', 'backup.manage'],
        'public' => ['/public', 'Public Portal', 'public.view'],
    ];
}


function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: same-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
}

function render_page(string $title, string $content, string $active = '', bool $full = true): void
{
    send_security_headers();
    $user = current_user();
    $flash = flash_take();
    $flashHtml = '';
    if ($flash) {
        $flashHtml = '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['msg']) . '</div>';
    }
    if (!$full || !$user) {
        // Minimal layout (public portal / login).
        $body = '<header class="topbar minimal"><a class="brand" href="' . e(app_url('/')) . '">' . e(APP_NAME) . '</a>'
            . ($user ? '<span class="user">' . e($user['name']) . ' · <a href="' . e(app_url('/logout')) . '">Logout</a></span>' : '')
            . '</header><main class="container narrow">' . $flashHtml . $content . '</main>'
            . '<footer class="footer">' . e(APP_NAME) . ' — ' . e(APP_TAGLINE) . '</footer>';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . ' · ' . e(APP_NAME) . '</title>'
            . '<link rel="stylesheet" href="' . e(app_url('/assets/app.css')) . '"></head><body>' . $body . '</body></html>';
        return;
    }

    $nav = '';
    foreach (layout_nav_items() as $key => [$path, $label, $perm]) {
        if (!user_has_permission($user, $perm)) {
            continue;
        }
        $cls = ($active === $key) ? 'nav-item active' : 'nav-item';
        $nav .= '<a class="' . $cls . '" href="' . e(app_url($path)) . '">' . e($label) . '</a>';
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' · ' . e(APP_NAME) . '</title>'
        . '<link rel="stylesheet" href="' . e(app_url('/assets/app.css')) . '"></head><body>'
        . '<header class="topbar"><a class="brand" href="' . e(app_url('/')) . '">' . e(APP_NAME) . '</a>'
        . '<span class="tagline">' . e(APP_TAGLINE) . '</span>'
        . '<div class="topbar-right"><span class="user">' . e($user['name']) . ($user['designation'] ? ' (' . e($user['designation']) . ')' : '') . '</span>'
        . '<a class="btn btn-small" href="' . e(app_url('/notifications')) . '">' . (unread_count((int) $user['id']) > 0 ? '🔔 (' . unread_count((int) $user['id']) . ')' : '🔔') . '</a>'
        . '<a class="btn btn-small btn-outline" href="' . e(app_url('/logout')) . '">Logout</a></div></header>'
        . '<div class="layout"><aside class="sidebar"><nav>' . $nav . '</nav></aside>'
        . '<main class="container">' . $flashHtml . $content . '</main></div>'
        . '<footer class="footer">' . e(APP_NAME) . ' — ' . e(APP_TAGLINE)
        . ' · This system records procurement data and runs configured checks. It does not constitute legal advice.</footer>'
        . '<script src="' . e(app_url('/assets/app.js')) . '"></script></body></html>';
    echo $html;
}

// ---------------------------------------------------------------------------
// Panchayat scope helpers (multi-panchayat ready)
// ---------------------------------------------------------------------------
function current_panchayat(): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    if ($user['panchayat_id'] !== null) {
        return DB::one('SELECT * FROM panchayats WHERE id = ?', [(int) $user['panchayat_id']]);
    }
    return panchayat_context();
}

function scope_condition(string $alias = ''): array
{
    // Return ['sql'=>' AND alias.panchayat_id = ?', 'params'=>[...]] for panchayat-scoped tables.
    $pid = current_scope_panchayat_id();
    if ($pid === null) {
        return ['sql' => '', 'params' => []];
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return ['sql' => ' AND ' . $prefix . 'panchayat_id = ?', 'params' => [$pid]];
}
