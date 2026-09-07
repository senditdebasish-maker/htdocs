<?php
/**
 * Web installer (rendered by install.php and the /install route).
 *
 * Self-healing: the database is created automatically on connect (MySQL) and
 * the schema is repaired in place — missing tables and columns are added and
 * reference data re-seeded without dropping existing data. Use "Repair
 * database" any time to bring an existing database back in line with the code.
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
    $report = ['created_tables' => [], 'added_columns' => []];

    try {
        $installed = schema_installed();
    } catch (Throwable $e) {
        $error = 'Database not reachable yet: ' . $e->getMessage();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
                throw new AppError(403, 'CSRF', 'Invalid CSRF token');
            }
            // Creates the database (if missing), all missing tables/columns and
            // the reference seed. Never drops existing data.
            $report = schema_heal();
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
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $content = '<div class="card"><h1>' . ($installed ? 'Database repair' : 'Installer') . '</h1>';
    if ($error) {
        $content .= '<div class="alert alert-danger">' . e($error) . '</div>';
    }
    if ($message) {
        $content .= '<div class="alert alert-success">' . e($message) . '</div>';
    }
    if ($installed) {
        $content .= '<p>The portal is installed and the database is healthy.</p>'
            . '<p>Seed administrator: <code>' . e(SEED_ADMIN_EMAIL) . '</code> (password from <code>config.php</code> — <code>SEED_ADMIN_PASSWORD</code>).</p>'
            . '<div class="actions">' . h_link('Open portal', '/', 'btn-primary')
            . h_form_open('/install', 'post')
            . '<button class="btn">Repair database</button></form></div>'
            . '<p class="muted">“Repair database” adds any missing tables/columns and re-runs the idempotent seed. It never deletes your data.</p>';
    } else {
        $content .= '<p>This will create the database (if missing), create all tables and seed the reference data. This may take a few seconds.</p>'
            . h_form_open('/install', 'post')
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
        . '<li>Import <code>install.sql</code> (it disables foreign-key checks during creation, so import order cannot fail).</li>'
        . '<li>Run <code>php seed.php</code>, or visit this page and click “Repair database”.</li></ol>'
        . '<p class="muted">Database settings live in <code>config.php</code>.</p></div>';

    render_page('Installer', $content, '', false);
}
