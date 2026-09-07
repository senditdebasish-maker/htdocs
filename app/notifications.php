<?php
/**
 * In-app notifications (channel-agnostic; today only 'inapp').
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function notify(
    string $type,
    string $title,
    ?string $body = null,
    ?int $userId = null,
    array $roleCodes = [],
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $link = null,
    string $severity = 'info'
): void {
    $recipients = [];
    if ($userId !== null) {
        $recipients[$userId] = true;
    }
    foreach ($roleCodes as $code) {
        foreach (DB::all(
            'SELECT DISTINCT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.code = ? AND u.is_active = 1 AND u.deleted_at IS NULL',
            [$code]
        ) as $r) {
            $recipients[(int) $r['id']] = true;
        }
    }
    foreach (array_keys($recipients) as $uid) {
        DB::insert(
            'INSERT INTO notifications (uid, user_id, type, title, body, entity_type, entity_id, link, severity, channel)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [uid(), $uid, $type, $title, $body, $entityType, $entityId, $link, $severity, 'inapp']
        );
    }
}

function unread_count(int $userId): int
{
    return (int) DB::val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
}
