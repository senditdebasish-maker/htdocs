<?php
/**
 * Shared helpers: money (decimal-safe), dates (Indian FY), uuid, escaping,
 * JSON I/O, application errors.
 *
 * Money convention: every monetary value is an INTEGER number of minor units
 * (paise, 1/100 of a rupee). No floating-point arithmetic is used for money.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// Application errors (status + code). Mirrors the Node error vocabulary.
// ---------------------------------------------------------------------------
class AppError extends Exception
{
    public int $status;
    public string $appCode;
    public $details;

    public function __construct(int $status, string $appCode, string $message, $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->appCode = $appCode;
        $this->details = $details;
    }
}

function err(int $status, string $code, string $message, $details = null): AppError
{
    return new AppError($status, $code, $message, $details);
}

function validation(string $message, $details = null): AppError { return err(422, 'VALIDATION_ERROR', $message, $details); }
function bad_request(string $message, $details = null): AppError { return err(400, 'BAD_REQUEST', $message, $details); }
function unauthorized(string $message = 'Authentication required'): AppError { return err(401, 'UNAUTHORIZED', $message); }
function forbidden(string $message = 'You do not have permission to perform this action'): AppError { return err(403, 'FORBIDDEN', $message); }
function not_found(string $message = 'Record not found'): AppError { return err(404, 'NOT_FOUND', $message); }
function conflict(string $message, $details = null): AppError { return err(409, 'CONFLICT', $message, $details); }

// ---------------------------------------------------------------------------
// Escaping
// ---------------------------------------------------------------------------
function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// UUID v4 (no external dependency)
// ---------------------------------------------------------------------------
function uid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ---------------------------------------------------------------------------
// Money — integer minor units only.
// ---------------------------------------------------------------------------
function minor($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return (int) round($v);
    }
    $n = (float) preg_replace('/[^0-9.\-]/', '', (string) $v);
    return (int) round($n);
}

function to_minor($rupees): int
{
    // Parse decimal currency as a string so routine money input does not depend
    // on binary floating point. Accepts values such as "1,23,456.78".
    $s = preg_replace('/[^0-9.\-]/', '', trim((string) $rupees));
    if ($s === '' || $s === '-' || $s === '.') {
        return 0;
    }
    $neg = false;
    if (str_starts_with($s, '-')) {
        $neg = true;
        $s = substr($s, 1);
    }
    $parts = explode('.', $s, 2);
    $whole = preg_replace('/\D/', '', $parts[0] ?? '0');
    $frac = preg_replace('/\D/', '', $parts[1] ?? '');
    $wholeMinor = ((int) ($whole === '' ? '0' : $whole)) * 100;
    $paise = (int) str_pad(substr($frac, 0, 2), 2, '0');
    $minor = $wholeMinor + $paise;
    return $neg ? -$minor : $minor;
}


/** Hash a password using Argon2id when available, falling back to PHP's secure default. */
function password_hash_secure(string $password): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

/** Convert blank form values to null before binding to nullable MySQL columns. */
function blank_to_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
}

/** Normalise an optional DATE input for MySQL/MariaDB DATE columns. */
function date_or_null($value): ?string
{
    $s = blank_to_null($value);
    if ($s === null) {
        return null;
    }
    $d = parse_date($s);
    if (!$d) {
        throw validation('Invalid date: ' . $s);
    }
    return $d->format('Y-m-d');
}

/** Normalise an optional DATETIME input for MySQL/MariaDB DATETIME columns. */
function datetime_or_null($value): ?string
{
    $s = blank_to_null($value);
    if ($s === null) {
        return null;
    }
    $s = str_replace('T', ' ', $s);
    foreach ([['!Y-m-d H:i:s', 'Y-m-d H:i:s', 19], ['!Y-m-d H:i', 'Y-m-d H:i', 16], ['!Y-m-d', 'Y-m-d', 10]] as $candidate) {
        [$inputFormat, $checkFormat, $length] = $candidate;
        $part = substr($s, 0, $length);
        $d = DateTimeImmutable::createFromFormat($inputFormat, $part);
        if ($d && $d->format($checkFormat) === $part) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    throw validation('Invalid date/time: ' . $s);
}

/** Return the panchayat id of an actor/session, or null for unrestricted global context. */
function actor_panchayat_id(?array $actor = null): ?int
{
    if ($actor && isset($actor['panchayat_id']) && $actor['panchayat_id'] !== null && $actor['panchayat_id'] !== '') {
        return (int) $actor['panchayat_id'];
    }
    if (function_exists('current_user')) {
        $u = current_user();
        if ($u && isset($u['panchayat_id']) && $u['panchayat_id'] !== null && $u['panchayat_id'] !== '') {
            return (int) $u['panchayat_id'];
        }
    }
    $pid = null;
    try {
        $pid = DB::val('SELECT id FROM panchayats ORDER BY id LIMIT 1');
    } catch (Throwable $e) {
        $pid = null;
    }
    return $pid !== null ? (int) $pid : null;
}

/** Current user's panchayat scope; global administrators with no panchayat return null. */
function current_scope_panchayat_id(): ?int
{
    if (!function_exists('current_user')) {
        return null;
    }
    $u = current_user();
    if (!$u) {
        return null;
    }
    if ((int) ($u['is_global_admin'] ?? 0) === 1 && (($u['panchayat_id'] ?? null) === null || $u['panchayat_id'] === '')) {
        return null;
    }
    return actor_panchayat_id($u);
}

/** SQL fragment for panchayat-scoped tables (safe because the id is integer-cast). */
function panchayat_scope_sql(string $alias = ''): string
{
    $pid = current_scope_panchayat_id();
    if ($pid === null) {
        return '';
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return ' AND ' . $prefix . 'panchayat_id = ' . (int) $pid;
}

/** Enforce tenant isolation for an already-loaded row. */
function scope_assert_row(?array $row, ?array $actor = null, string $label = 'record'): void
{
    if (!$row || !array_key_exists('panchayat_id', $row)) {
        return;
    }
    $actor = $actor ?: (function_exists('current_user') ? current_user() : null);
    if (!$actor) {
        return;
    }
    if ((int) ($actor['is_global_admin'] ?? 0) === 1 && (($actor['panchayat_id'] ?? null) === null || $actor['panchayat_id'] === '')) {
        return;
    }
    $pid = actor_panchayat_id($actor);
    if ($pid !== null && $row['panchayat_id'] !== null && (int) $row['panchayat_id'] !== $pid) {
        throw forbidden('Cross-Panchayat access denied for this ' . $label);
    }
}

function from_minor($minor): string
{
    $m = (int) ($minor ?? 0);
    $neg = $m < 0;
    $m = abs($m);
    $whole = intdiv($m, 100);
    $frac = $m % 100;
    return ($neg ? '-' : '') . $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
}

/** Indian-grouped currency string, e.g. ₹12,34,567.89 */
function inr($minor): string
{
    $m = (int) ($minor ?? 0);
    $neg = $m < 0;
    $m = abs($m);
    $whole = (string) intdiv($m, 100);
    $frac = str_pad((string) ($m % 100), 2, '0', STR_PAD_LEFT);
    $w = $whole;
    if (strlen($w) <= 3) {
        $out = $w;
    } else {
        $last3 = substr($w, -3);
        $rest = substr($w, 0, -3);
        $parts = [];
        while (strlen($rest) > 2) {
            array_unshift($parts, substr($rest, -2));
            $rest = substr($rest, 0, -2);
        }
        if ($rest !== '') {
            array_unshift($parts, $rest);
        }
        $out = implode(',', $parts) . ',' . $last3;
    }
    return ($neg ? '-' : '') . '₹' . $out . '.' . $frac;
}

function qty_rate($quantity, $rateMinor): int
{
    return (int) round(((float) $quantity) * ((float) $rateMinor));
}

function pct_of($baseMinor, $pct): int
{
    return (int) round(((float) $baseMinor) * ((float) $pct / 100.0));
}

function pct_diff($baseMinor, $valueMinor)
{
    $b = (int) $baseMinor;
    $v = (int) $valueMinor;
    if ($b === 0) {
        return null;
    }
    return (($v - $b) / $b) * 100;
}

// ---------------------------------------------------------------------------
// Dates & Indian financial years (Apr–Mar)
// ---------------------------------------------------------------------------
function today_iso(): string
{
    return date('Y-m-d');
}

function now_iso(): string
{
    // MySQL/MariaDB DATETIME columns do not accept ISO-8601 timezone strings
    // reliably under strict SQL modes. Store local Asia/Kolkata time in the
    // portable DATETIME format instead.
    return date('Y-m-d H:i:s');
}

function parse_date(?string $s): ?DateTimeImmutable
{
    if ($s === null || $s === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($s), 0, 10));
    if ($d === false) {
        return null;
    }
    $check = $d->format('Y-m-d');
    return ($check === substr(trim($s), 0, 10)) ? $d : null;
}

function fy_label($date): string
{
    $ts = is_string($date) ? strtotime(substr($date, 0, 10)) : $date;
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $start = $m >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string) ($start + 1), -2);
}

function parse_fy(?string $label): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', trim((string) $label), $m)) {
        return null;
    }
    $start = (int) $m[1];
    $end = (int) $m[2];
    if ($end !== (int) substr((string) ($start + 1), -2)) {
        return null;
    }
    return [
        'startYear' => $start,
        'endYear' => $start + 1,
        'start' => $start . '-04-01',
        'end' => ($start + 1) . '-03-31',
        'label' => $label,
    ];
}

function days_between(?string $aISO, ?string $bISO): ?int
{
    $a = parse_date($aISO);
    $b = parse_date($bISO);
    if (!$a || !$b) {
        return null;
    }
    return (int) floor(($b->getTimestamp() - $a->getTimestamp()) / 86400);
}

function is_after(?string $aISO, ?string $bISO): bool
{
    $a = parse_date($aISO);
    $b = parse_date($bISO);
    if (!$a || !$b) {
        return false;
    }
    return $a > $b;
}

function fmt_date(?string $iso): string
{
    $d = parse_date($iso);
    return $d ? $d->format('d M Y') : '—';
}

function fmt_dt(?string $iso): string
{
    $t = strtotime((string) $iso);
    return $t ? date('d M Y H:i', $t) : '';
}

// ---------------------------------------------------------------------------
// JSON I/O
// ---------------------------------------------------------------------------
function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? $data : [];
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function error_out(AppError $e): void
{
    http_response_code($e->status);
    header('Content-Type: application/json; charset=utf-8');
    $body = [
        'error' => [
            'message' => $e->status >= 500 ? 'An unexpected error occurred.' : $e->getMessage(),
            'code' => $e->appCode,
        ],
    ];
    if ($e->status < 500 && $e->details !== null) {
        $body['error']['details'] = $e->details;
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON encode a value for storage in a TEXT column. */
function json_store($value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** JSON decode a stored value with a fallback. */
function json_load(?string $value, $fallback = null)
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    $d = json_decode($value, true);
    return is_array($d) ? $d : $fallback;
}

/** Coerce a value to int, empty => null. */
function int_or_null($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    return (int) $v;
}

/**
 * Quote an SQL identifier for the active driver. MySQL/MariaDB use backticks
 * (required for reserved words such as `rank`, `key`, `value`); SQLite uses
 * plain names (backticks are not valid there).
 */
function sql_ident(string $id): string
{
    return DB::isSqlite() ? $id : '`' . $id . '`';
}
