<?php
/**
 * Offline backup/restore helpers.
 *
 * Backups are JSON snapshots of database tables generated from live data and
 * stored below DATA_DIR/backups (protected by .htaccess). Restore is deliberately
 * restricted to global administrators because a full snapshot may contain data
 * for multiple Panchayats.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/audit.php';

function backup_require_global_admin(array $actor): void
{
    if ((int) ($actor['is_global_admin'] ?? 0) !== 1) {
        throw forbidden('Full database backup/restore is restricted to global administrators to avoid cross-Panchayat data exposure.');
    }
}

function backup_safe_file_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    if ($name === '' || strpos($name, '..') !== false || !preg_match('/\.json$/i', $name)) {
        throw forbidden('Invalid backup file name');
    }
    return $name;
}

function backup_file_path(string $name): string
{
    $name = backup_safe_file_name($name);
    $base = realpath(BACKUPS_DIR) ?: BACKUPS_DIR;
    $path = BACKUPS_DIR . '/' . $name;
    $real = realpath($path);
    $base = rtrim((string) $base, DIRECTORY_SEPARATOR);
    if (!$real || ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) || !is_file($real)) {
        throw not_found('Backup file not found');
    }
    return $real;
}

function backup_create(array $actor): array
{
    backup_require_global_admin($actor);
    if (!is_dir(BACKUPS_DIR)) {
        @mkdir(BACKUPS_DIR, 0775, true);
    }
    $tables = array_keys(schema_tables());
    $snapshot = [
        'format' => 'gp-portal-json-backup-v1',
        'app' => APP_NAME,
        'created_at' => date('c'),
        'schema_version' => schema_version(),
        'driver' => DB::driver(),
        'tables' => [],
    ];
    foreach ($tables as $table) {
        $snapshot['tables'][$table] = DB::all('SELECT * FROM ' . sql_ident($table));
    }
    $file = 'gp-backup-' . date('Ymd-His') . '-' . substr(uid(), 0, 8) . '.json';
    $path = BACKUPS_DIR . '/' . $file;
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw err(500, 'BACKUP_JSON_FAILED', 'Unable to encode backup JSON.');
    }
    file_put_contents($path, $json);
    @chmod($path, 0640);
    $backupId = DB::insert(
        'INSERT INTO backups (uid, panchayat_id, backup_type, file_name, size_bytes, status, verified, created_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [uid(), actor_panchayat_id($actor), 'json', $file, filesize($path), 'created', 1, (int) $actor['id']]
    );
    // Include this backup-register row in the snapshot so the file can restore
    // its own metadata on a fresh database.
    $currentRow = DB::one('SELECT * FROM backups WHERE id = ?', [$backupId]);
    if ($currentRow) {
        $snapshot['tables']['backups'][] = $currentRow;
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($path, $json);
            DB::run('UPDATE backups SET size_bytes = ? WHERE id = ?', [filesize($path), $backupId]);
        }
    }
    audit_record('backup.create', 'backup', $backupId, $file, ['newValue' => ['schemaVersion' => schema_version(), 'size' => filesize($path)]]);
    return DB::one('SELECT * FROM backups WHERE id = ?', [$backupId]);
}

function backup_list(array $actor): array
{
    backup_require_global_admin($actor);
    return DB::all('SELECT b.*, u.name created_by_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 100');
}

function backup_restore(int $backupId, array $actor): array
{
    backup_require_global_admin($actor);
    $backup = DB::one('SELECT * FROM backups WHERE id = ?', [$backupId]);
    if (!$backup) {
        throw not_found('Backup not found');
    }
    $path = backup_file_path((string) $backup['file_name']);
    $raw = file_get_contents($path);
    $snapshot = json_decode($raw ?: '', true);
    if (!is_array($snapshot) || ($snapshot['format'] ?? '') !== 'gp-portal-json-backup-v1' || empty($snapshot['tables']) || !is_array($snapshot['tables'])) {
        throw validation('Backup file is not a valid GP Portal JSON backup.');
    }
    $pdo = DB::pdo();
    $restoreFk = schema_fk_off();
    try {
        $pdo->beginTransaction();
        $tables = array_keys(schema_tables());
        foreach (array_reverse($tables) as $table) {
            $pdo->exec('DELETE FROM ' . sql_ident($table));
        }
        foreach ($tables as $table) {
            $rows = $snapshot['tables'][$table] ?? [];
            if (!$rows) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                $cols = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO ' . sql_ident($table) . ' (' . implode(',', array_map('sql_ident', $cols)) . ') VALUES (' . $placeholders . ')';
                DB::run($sql, array_values($row));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    } finally {
        $pdo->exec($restoreFk);
    }
    audit_record('backup.restore', 'backup', $backupId, (string) $backup['file_name'], ['reason' => 'Full database snapshot restored by global administrator']);
    return ['restored' => true, 'file' => $backup['file_name'], 'schema_version' => $snapshot['schema_version'] ?? null];
}
