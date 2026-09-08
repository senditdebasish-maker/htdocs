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
    $user = function_exists('current_user') ? current_user() : null;
    try {
        $prevHash = null;
        try {
            $prevHash = DB::val('SELECT entry_hash FROM audit_logs ORDER BY id DESC LIMIT 1');
        } catch (Throwable $ignore) {
            $prevHash = null;
        }
        $payload = [
            'uid' => uid(),
            'actor_id' => $user ? (int) $user['id'] : null,
            'actor_name' => $user ? $user['name'] : null,
            'actor_role' => null,
            'panchayat_id' => $user ? ($user['panchayat_id'] !== null ? (int) $user['panchayat_id'] : null) : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $entityLabel,
            'old_value' => isset($extra['oldValue']) ? json_store($extra['oldValue']) : null,
            'new_value' => isset($extra['newValue']) ? json_store($extra['newValue']) : null,
            'reason' => $extra['reason'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'request_id' => $extra['requestId'] ?? null,
            'prev_hash' => $prevHash,
        ];
        $payload['entry_hash'] = hash('sha256', json_store($payload));
        DB::insert(
            'INSERT INTO audit_logs
               (uid, actor_id, actor_name, actor_role, panchayat_id, action, entity_type, entity_id,
                entity_label, old_value, new_value, reason, ip_address, user_agent, request_id, prev_hash, entry_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $payload['uid'], $payload['actor_id'], $payload['actor_name'], $payload['actor_role'], $payload['panchayat_id'],
                $payload['action'], $payload['entity_type'], $payload['entity_id'], $payload['entity_label'],
                $payload['old_value'], $payload['new_value'], $payload['reason'], $payload['ip_address'],
                $payload['user_agent'], $payload['request_id'], $payload['prev_hash'], $payload['entry_hash'],
            ]
        );
    } catch (Throwable $e) {
        // An audit failure must never break the primary request.
        error_log('[audit] failed to record: ' . $e->getMessage());
    }
}
