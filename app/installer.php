<?php
/**
 * Web installer (rendered by install.php and the /install route).
 *
 * The setup page is only open before the first administrator exists. After
 * installation, database repair is available only to an authenticated user with
 * settings.manage; unauthenticated visitors see a disabled installer message.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/view.php';

function install_page(): void
{
    start_app_session();
    $message = '';
    $error = '';
    $installed = false;
    $hasUsers = false;
    $report = ['created_tables' => [], 'added_columns' => []];

    try {
        $installed = schema_installed();
        $hasUsers = $installed && ((int) DB::val('SELECT COUNT(*) FROM users') > 0);
    } catch (Throwable $e) {
        $error = 'Database not reachable yet: ' . $e->getMessage();
    }

    $current = function_exists('current_user') ? current_user() : null;
    $canRepair = $current && user_has_permission($current, 'settings.manage');

    if ($hasUsers && !$canRepair) {
        $content = '<div class="card"><h1>Installer disabled</h1>'
            . '<p>The application is already installed. For security, setup/repair is available only after signing in as an administrator.</p>'
            . '<div class="actions">' . h_link('Sign in', '/login', 'btn-primary') . h_link('Open public portal', '/public', 'btn-outline') . '</div></div>';
        render_page('Installer disabled', $content, '', false);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
                throw new AppError(403, 'CSRF', 'Invalid CSRF token');
            }
            if ($hasUsers && !$canRepair) {
                throw forbidden('Installer repair requires administrator sign-in');
            }

            $admin = null;
            if (!$hasUsers) {
                $password = (string) ($_POST['admin_password'] ?? '');
                if ($password !== (string) ($_POST['admin_password_confirm'] ?? '')) {
                    throw validation('Administrator password and confirmation do not match.');
                }
                $email = strtolower(trim((string) ($_POST['admin_email'] ?? SEED_ADMIN_EMAIL)));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw validation('Enter a valid administrator email address.');
                }
                $admin = [
                    'name' => trim((string) ($_POST['admin_name'] ?? 'System Administrator')) ?: 'System Administrator',
                    'email' => $email,
                    'password' => $password,
                ];
            }

            // Creates the database (if missing), all missing tables/columns and
            // the reference seed. Never drops existing data.
            $report = schema_heal($admin);
            $message = 'Database is ready.';
            if ($report['created_tables']) {
                $message .= ' Created ' . count($report['created_tables']) . ' table(s).';
            }
            $added = 0;
            foreach ($report['added_columns'] as $cols) {
                $added += count($cols);
            }
            if ($added) {
                $message .= ' Added ' . $added . ' missing column(s).';
            }
            $installed = schema_installed();
            $hasUsers = $installed && ((int) DB::val('SELECT COUNT(*) FROM users') > 0);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $content = '<div class="card"><h1>' . ($installed ? 'Database repair' : 'Secure installer') . '</h1>';
    if ($error) {
        $content .= '<div class="alert alert-danger">' . e($error) . '</div>';
    }
    if ($message) {
        $content .= '<div class="alert alert-success">' . e($message) . '</div>';
    }
    if ($hasUsers) {
        $content .= '<p>The portal is installed and the database is healthy.</p>'
            . '<div class="actions">' . h_link('Open portal', '/', 'btn-primary')
            . h_form_open('/install', 'post')
            . '<button class="btn">Repair database</button></form></div>'
            . '<p class="muted">“Repair database” adds missing tables/columns/indexes and re-runs idempotent reference seeders. It never deletes your data.</p>';
    } else {
        $content .= '<p>This creates/repairs the database and creates the first Super Administrator. No default production password is shipped.</p>'
            . h_form_open('/install', 'post')
            . '<div class="field-row">' . h_input('Admin name', 'admin_name', 'System Administrator', 'text', true) . h_input('Admin email', 'admin_email', SEED_ADMIN_EMAIL, 'email', true) . '</div>'
            . '<div class="field-row">' . h_input('Admin password', 'admin_password', '', 'password', true, 'Minimum 10 characters with upper-case, lower-case and number.') . h_input('Confirm password', 'admin_password_confirm', '', 'password', true) . '</div>'
            . h_submit('Install now', 'btn-primary')
            . '</form>';
    }
    $content .= '</div>';

    $health = [];
    try {
        $health = schema_health();
    } catch (Throwable $e) {
        // introspection unavailable; leave the card empty
    }
    $content .= '<div class="card"><h2>Database status</h2>'
        . '<dl class="dl">'
        . '<dt>Driver</dt><dd>' . e(DB::driver()) . '</dd>'
        . '<dt>Schema version</dt><dd>' . e(schema_version()) . '</dd>'
        . '<dt>Tables missing</dt><dd>' . ($health ? (count($health['missing_tables']) ? e(implode(', ', $health['missing_tables'])) : 'none') : 'unknown') . '</dd>'
        . '<dt>Columns missing</dt><dd>' . ($health ? ((count($health['missing_columns'])) ? e((string) count($health['missing_columns'])) . ' table(s) affected' : 'none') : 'unknown') . '</dd>'
        . '</dl></div>';

    $content .= '<div class="card"><h2>Manual alternative (phpMyAdmin)</h2>'
        . '<p>The app normally creates and repairs the database automatically. If you prefer to import by hand:</p>'
        . '<ol><li>Create a database <code>' . e(DB_NAME) . '</code> (utf8mb4_unicode_ci).</li>'
        . '<li>Import <code>install.sql</code>.</li>'
        . '<li>Set <code>SEED_ADMIN_PASSWORD</code> in <code>config.php</code> or as an environment variable, then run <code>php seed.php</code>.</li></ol>'
        . '<p class="muted">Database settings live in <code>config.php</code>; production secrets should be supplied locally and not committed.</p></div>';

    render_page('Installer', $content, '', false);
}
