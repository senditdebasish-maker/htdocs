<?php
/**
 * Authentication, sessions, CSRF and role/permission checks.
 * All authorization is enforced server-side here.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => (base_path() !== '' ? base_path() : '/'),
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    if (!empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > SESSION_LIFETIME) {
        $expiredUserId = (int) $_SESSION['user_id'];
        // Avoid recursive current_user() -> audit_record() -> current_user() calls.
        unset($_SESSION['user_id']);
        if (function_exists('audit_record')) { audit_record('auth.session_expired', 'user', $expiredUserId, null); }
        logout_user();
        return null;
    }
    $_SESSION['last_activity'] = time();

    static $cacheKey = null;
    static $user = null;
    // Cache keyed on the session user so a login (or a session change) later
    // in the same request is honoured instead of returning a stale null.
    $key = (int) $_SESSION['user_id'];
    if ($cacheKey !== $key) {
        $user = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$key]);
        if (!$user || !(int) $user['is_active']) {
            $user = null;
        }
        $cacheKey = $key;
    }
    return $user;
}

// ---------------------------------------------------------------------------
// CSRF (all state-changing POST/PUT/DELETE requests must present the token)
// ---------------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
        throw forbidden('Invalid or missing CSRF token');
    }
}

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------
function user_permissions(int $userId): array
{
    $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return [];
    }
    if ((int) $user['is_global_admin'] === 1) {
        $perms = [];
        foreach (DB::all('SELECT code FROM permissions') as $r) {
            $perms[$r['code']] = true;
        }
        return $perms;
    }
    $perms = [];
    $rows = DB::all(
        'SELECT DISTINCT p.code FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE ur.user_id = ?',
        [$userId]
    );
    foreach ($rows as $r) {
        $perms[$r['code']] = true;
    }
    return $perms;
}

function user_roles(int $userId): array
{
    return DB::all('SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [$userId]);
}

function user_has_permission(array $user, string $code): bool
{
    if ((int) ($user['is_global_admin'] ?? 0) === 1) {
        return true;
    }
    return isset(user_permissions((int) $user['id'])[$code]);
}

/** Require a logged-in user; throws 401 otherwise. */
function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        throw unauthorized();
    }
    return $user;
}

function require_permission(string $code): array
{
    $user = require_auth();
    if (!user_has_permission($user, $code)) {
        throw forbidden("Missing permission: $code");
    }
    return $user;
}

function require_any_permission(string ...$codes): array
{
    $user = require_auth();
    foreach ($codes as $c) {
        if (user_has_permission($user, $c)) {
            return $user;
        }
    }
    throw forbidden();
}

// ---------------------------------------------------------------------------
// Login / logout
// ---------------------------------------------------------------------------
function login_user(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $user = DB::one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        audit_record('auth.login_locked', 'user', (int) $user['id'], $email);
        throw err(403, 'ACCOUNT_LOCKED', 'Account temporarily locked because of repeated failed sign-in attempts');
    }
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        if ($user) {
            $fails = (int) $user['failed_login_count'] + 1;
            $lockedUntil = null;
            if (RATE_LIMIT_ENABLED && $fails >= RATE_LIMIT_MAX) {
                $lockedUntil = date('Y-m-d H:i:s', time() + RATE_LIMIT_WINDOW);
                $fails = 0;
            }
            DB::run('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?', [$fails, $lockedUntil, (int) $user['id']]);
        }
        audit_record('auth.login_failed', 'user', $user ? (int) $user['id'] : null, $email);
        throw err(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
    }
    if (!(int) $user['is_active']) {
        throw err(403, 'ACCOUNT_DISABLED', 'Account is disabled');
    }
    DB::run('UPDATE users SET last_login_at = CURRENT_TIMESTAMP, failed_login_count = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    audit_record('auth.login', 'user', $user['id'], $user['email']);
    $roles = user_roles((int) $user['id']);
    return [
        'user' => [
            'id' => (int) $user['id'],
            'uid' => $user['uid'],
            'name' => $user['name'],
            'email' => $user['email'],
            'designation' => $user['designation'],
            'is_global_admin' => (int) $user['is_global_admin'],
            'panchayat_id' => $user['panchayat_id'] !== null ? (int) $user['panchayat_id'] : null,
        ],
        'roles' => array_map(function ($r) { return ['code' => $r['code'], 'name' => $r['name']]; }, $roles),
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
