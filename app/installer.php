<?php
/**
 * Web installer (rendered by install.php and the /install route).
 * Creates the MySQL database (if missing), the schema and the reference seed.
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

    try {
        $installed = schema_installed();
    } catch (Throwable $e) {
        $error = 'Database not reachable yet: ' . $e->getMessage();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
        try {
            if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
                throw new AppError(403, 'CSRF', 'Invalid CSRF token');
            }
            if (DB_DRIVER === 'mysql') {
                try {
                    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET), DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . addcslashes(DB_NAME, '`') . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (Throwable $e) {
                    throw new AppError(500, 'DB_CONNECT', 'Could not connect to MySQL at ' . DB_HOST . ' as "' . DB_USER . '". Ensure MySQL is running in XAMPP and check credentials in config.php. — ' . $e->getMessage());
                }
            }
            create_tables(true);
            seed_all(false);
            $message = 'Installation complete. Seed administrator: ' . SEED_ADMIN_EMAIL;
            $installed = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $content = '<div class="card"><h1>Installer</h1>';
    if ($error) {
        $content .= '<div class="alert alert-danger">' . e($error) . '</div>';
    }
    if ($message) {
        $content .= '<div class="alert alert-success">' . e($message) . '</div>';
    }
    if ($installed) {
        $content .= '<p>The portal is installed and seeded.</p>'
            . '<p>Seed administrator: <code>' . e(SEED_ADMIN_EMAIL) . '</code> (password from config.php SEED_ADMIN_PASSWORD).</p>'
            . '<div class="actions">' . h_link('Open portal', '/', 'btn-primary') . '</div>';
    } else {
        $content .= '<p>This will create the MySQL database (if missing), create all tables and seed the reference data. This may take a few seconds.</p>'
            . h_form_open('/install', 'post')
            . h_submit('Install now', 'btn-primary')
            . '</form>';
    }
    $content .= '</div>';
    $content .= '<div class="card"><h2>Manual alternative</h2>'
        . '<p>You can also import the schema in phpMyAdmin:</p>'
        . '<ol><li>Open <code>install.sql</code> from the <code>gp-portal</code> folder.</li>'
        . '<li>Run it against a new <code>' . e(DB_NAME) . '</code> database (utf8mb4_unicode_ci).</li>'
        . '<li>Run <code>php seed.php</code> from the command line, or visit this page and click "Install now".</li></ol>'
        . '<p class="muted">Database settings live in <code>config.php</code>.</p></div>';

    render_page('Installer', $content, '', false);
}
