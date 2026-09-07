<?php
/**
 * Configurable approval workflow engine.
 * Role requirements are enforced server-side on every action.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

const DEFAULT_WORKFLOWS = [
    'tender' => [
        'steps' => [
            ['name' => 'Technical Verification', 'requiredRole' => 'technical_officer'],
            ['name' => 'Secretary Approval', 'requiredRole' => 'panchayat_secretary'],
            ['name' => 'Pradhan Approval', 'requiredRole' => 'pradhan'],
            ['name' => 'Tender Committee', 'requiredRole' => 'tender_committee'],
            ['name' => 'Competent Authority', 'requiredRole' => 'pradhan'],
        ],
    ],
    'bill' => [
        'steps' => [
            ['name' => 'Submitted', 'requiredRole' => null],
            ['name' => 'Technical Check', 'requiredRole' => 'technical_officer'],
            ['name' => 'Accounts Certification', 'requiredRole' => 'accounts_officer'],
            ['name' => 'Approval', 'requiredRole' => 'pradhan'],
        ],
    ],
    'corrigendum' => [
        'steps' => [
            ['name' => 'Secretary Approval', 'requiredRole' => 'panchayat_secretary'],
            ['name' => 'Competent Authority', 'requiredRole' => 'pradhan'],
        ],
    ],
];

function workflow_config(string $entityType): array
{
    $stored = DB::one('SELECT ' . sql_ident('value') . ' FROM settings WHERE ' . sql_ident('key') . ' = ?', ['workflow.' . $entityType]);
    if ($stored && $stored['value']) {
        $decoded = json_load($stored['value']);
        if ($decoded !== null) {
            return $decoded;
        }
    }
    return DEFAULT_WORKFLOWS[$entityType] ?? ['steps' => []];
}

function workflow_init(string $entityType, int $entityId, array $opts = []): array
{
    $config = $opts['config'] ?? workflow_config($entityType);
    $count = (int) DB::val('SELECT COUNT(*) FROM approval_steps WHERE entity_type = ? AND entity_id = ?', [$entityType, $entityId]);
    if ($count > 0 && empty($opts['reset'])) {
        return workflow_get($entityType, $entityId);
    }
    if (!empty($opts['reset'])) {
        DB::run('DELETE FROM approval_steps WHERE entity_type = ? AND entity_id = ?', [$entityType, $entityId]);
    }
    $i = 1;
    foreach ($config['steps'] as $s) {
        DB::insert(
            'INSERT INTO approval_steps (uid, entity_type, entity_id, step_order, step_name, required_role, status)
             VALUES (?,?,?,?,?,?,?)',
            [uid(), $entityType, $entityId, $i, $s['name'], $s['requiredRole'] ?? null, $i === 1 ? 'in_progress' : 'pending']
        );
        $i++;
    }
    return workflow_get($entityType, $entityId);
}

function workflow_get(string $entityType, int $entityId): array
{
    return DB::all('SELECT * FROM approval_steps WHERE entity_type = ? AND entity_id = ? ORDER BY step_order', [$entityType, $entityId]);
}

function workflow_current_step(string $entityType, int $entityId): ?array
{
    foreach (workflow_get($entityType, $entityId) as $s) {
        if ($s['status'] === 'in_progress' || $s['status'] === 'pending') {
            return $s;
        }
    }
    return null;
}

function workflow_all_approved(array $steps): bool
{
    if (!$steps) {
        return false;
    }
    foreach ($steps as $s) {
        if ($s['status'] !== 'approved' && $s['status'] !== 'skipped') {
            return false;
        }
    }
    return true;
}

function workflow_actor_can_act(array $actor, ?string $requiredRole): bool
{
    if (!(int) ($actor['is_global_admin'] ?? 0) && !empty($requiredRole)) {
        $roles = user_roles((int) $actor['id']);
        $ok = false;
        foreach ($roles as $r) {
            if ($r['code'] === $requiredRole) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/**
 * Perform a workflow action. Returns ['step'=>..., 'done'=>bool, 'steps'=>...].
 */
function workflow_act(string $entityType, int $entityId, string $action, ?string $remarks, array $actor, ?callable $onComplete = null): array
{
    if (!in_array($action, ['approve', 'reject', 'return', 'clarification', 'cancel'], true)) {
        throw bad_request('Invalid workflow action');
    }
    $step = workflow_current_step($entityType, $entityId);
    if (!$step) {
        throw bad_request('No pending workflow step');
    }
    if (!workflow_actor_can_act($actor, $step['required_role'])) {
        throw forbidden('Step "' . $step['step_name'] . '" requires the "' . $step['required_role'] . '" role');
    }
    $statusByAction = ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned', 'clarification' => 'clarification', 'cancel' => 'cancelled'];
    $stepStatus = $statusByAction[$action];

    DB::run(
        'UPDATE approval_steps SET status = ?, actor_id = ?, action = ?, remarks = ?, acted_at = CURRENT_TIMESTAMP WHERE id = ?',
        [$stepStatus, (int) $actor['id'], $action, $remarks, (int) $step['id']]
    );
    audit_record('workflow.' . $action, $entityType, $entityId, 'step ' . $step['step_order'] . ': ' . $step['step_name'], ['reason' => $remarks]);

    $done = false;
    if ($action === 'approve') {
        $next = DB::one(
            "SELECT * FROM approval_steps WHERE entity_type = ? AND entity_id = ? AND step_order > ? AND status = 'pending' ORDER BY step_order LIMIT 1",
            [$entityType, $entityId, (int) $step['step_order']]
        );
        if ($next) {
            DB::run("UPDATE approval_steps SET status = 'in_progress' WHERE id = ?", [(int) $next['id']]);
            if ($next['required_role']) {
                notify(
                    'approval_pending',
                    'Approval required: ' . $entityType . ' #' . $entityId,
                    'Step "' . $next['step_name'] . '" is awaiting your action.',
                    null,
                    [$next['required_role']],
                    $entityType,
                    $entityId,
                    null,
                    'info'
                );
            }
        } else {
            $done = true;
        }
    }

    $after = workflow_get($entityType, $entityId);
    if ($done && $onComplete) {
        $onComplete($actor);
    }
    return [
        'step' => DB::one('SELECT * FROM approval_steps WHERE id = ?', [(int) $step['id']]),
        'done' => $done,
        'steps' => $after,
    ];
}
