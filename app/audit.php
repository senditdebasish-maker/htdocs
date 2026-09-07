<?php
/**
 * Append-only audit trail. The audit service only ever inserts; no role may
 * update or delete audit rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function audit_record(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $entityLabel = null,
    array $extra = []
): void {
    $user = current_user();
    try {
        DB::insert(
            'INSERT INTO audit_logs
               (uid, actor_id, actor_name, actor_role, panchayat_id, action, entity_type, entity_id,
                entity_label, old_value, new_value, reason, ip_address, user_agent, request_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                uid(),
                $user ? (int) $user['id'] : null,
                $user ? $user['name'] : null,
                null,
                $user ? ($user['panchayat_id'] !== null ? (int) $user['panchayat_id'] : null) : null,
                $action,
                $entityType,
                $entityId,
                $entityLabel,
                isset($extra['oldValue']) ? json_store($extra['oldValue']) : null,
                isset($extra['newValue']) ? json_store($extra['newValue']) : null,
                $extra['reason'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
                $extra['requestId'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        // An audit failure must never break the primary request.
        error_log('[audit] failed to record: ' . $e->getMessage());
    }
}
