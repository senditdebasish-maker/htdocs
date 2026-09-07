<?php
/**
 * GP Procurement & Work Management Portal — configuration.
 *
 * All settings can be overridden via environment variables or by editing this
 * file. Defaults target a stock XAMPP installation (MySQL on localhost).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Database
//   DB_DRIVER  = 'mysql' (XAMPP default) | 'sqlite'
// ---------------------------------------------------------------------------
define('DB_DRIVER', env('DB_DRIVER', 'mysql'));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'gp_portal'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));           // XAMPP MySQL root has empty password by default
define('DB_CHARSET', 'utf8mb4');
// SQLite file (only used when DB_DRIVER = 'sqlite'), relative to this directory.
define('DB_SQLITE_FILE', env('DB_SQLITE_FILE', __DIR__ . '/data/app.db'));

// ---------------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------------
define('APP_NAME', 'Gram Panchayat Procurement & Work Management Portal');
define('APP_SHORT', 'GP Portal');
define('APP_TAGLINE', 'FY → Scheme → Project → Tender → Award → Execution → Bill → Payment → Audit');
define('SESSION_NAME', 'GPPSESSID');
define('SESSION_COOKIE_SECURE', (bool) env('SESSION_COOKIE_SECURE', '0')); // set to 1 only over HTTPS
define('SESSION_LIFETIME', (int) env('SESSION_LIFETIME', 28800));          // seconds
define('MAX_UPLOAD_BYTES', (int) env('MAX_UPLOAD_BYTES', 25 * 1024 * 1024));
define('RATE_LIMIT_ENABLED', (bool) env('RATE_LIMIT_ENABLED', '1'));
define('RATE_LIMIT_MAX', (int) env('RATE_LIMIT_MAX', 30));                // attempts per window
define('RATE_LIMIT_WINDOW', (int) env('RATE_LIMIT_WINDOW', 900));         // seconds

// Seed admin (used only on first install).
define('SEED_ADMIN_EMAIL', env('SEED_ADMIN_EMAIL', 'admin@panchayat.local'));
define('SEED_ADMIN_PASSWORD', env('SEED_ADMIN_PASSWORD', 'ChangeMe@12345'));

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
define('APP_ROOT', __DIR__);
define('DATA_DIR', env('DATA_DIR', APP_ROOT . '/data'));
define('UPLOADS_DIR', DATA_DIR . '/uploads');
define('GENERATED_DIR', DATA_DIR . '/generated');
define('BACKUPS_DIR', DATA_DIR . '/backups');
define('LOG_DIR', DATA_DIR . '/logs');

/** Read an environment variable with a fallback default. */
function env(string $key, $default = ''): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return (string) $default;
    }
    return (string) $v;
}

/**
 * Base URL path under which the app is installed (e.g. '/gp-portal').
 * Computed from SCRIPT_NAME so the app works both at the web root and inside
 * a sub-folder of htdocs.
 */
function base_path(): string
{
    static $bp = null;
    if ($bp !== null) {
        return $bp;
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.' || $dir === '') {
        $bp = '';
    } else {
        $bp = rtrim($dir, '/');
    }
    return $bp;
}

/** Build an absolute URL path for the app (e.g. '/gp-portal/tenders'). */
function u(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}
