<?php
/**
 * Direct-access installer shim. The main front controller also routes /install
 * here, so this file is a convenience for plain XAMPP setups.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/schema.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/audit.php';
require_once __DIR__ . '/app/seed.php';
require_once __DIR__ . '/app/view.php';
require_once __DIR__ . '/app/installer.php';

// Self-healing bootstrap: DB::connect() creates the database if missing.
try {
    DB::pdo();
} catch (Throwable $e) {
    http_response_code(503);
    render_page('Database unavailable', '<div class="alert alert-danger"><h1>Database unavailable</h1>'
        . '<p>' . e($e->getMessage()) . '</p>'
        . '<p class="muted">Check that MySQL is running in XAMPP and that the credentials in <code>config.php</code> are correct.</p></div>', '', false);
    exit;
}
install_page();
