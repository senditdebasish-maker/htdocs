<?php
/**
 * Command-line seeder shim (convenience: `php seed.php --demo`).
 * The full seeder lives in app/seed.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/schema.php';
require_once __DIR__ . '/app/audit.php';
require_once __DIR__ . '/app/notifications.php';
require_once __DIR__ . '/app/workflow.php';
require_once __DIR__ . '/app/compliance.php';
require_once __DIR__ . '/app/services.php';
require_once __DIR__ . '/app/documents.php';
require_once __DIR__ . '/app/seed.php';

DB::pdo();
$args = $GLOBALS['argv'] ?? [];
if (in_array('--demo-reset', $args, true)) {
    echo json_encode(demo_reset(), JSON_PRETTY_PRINT) . "\n";
    exit;
}
if (in_array('--heal', $args, true)) {
    echo json_encode(schema_heal(), JSON_PRETTY_PRINT) . "\n";
    exit;
}
if (in_array('--check', $args, true)) {
    $h = schema_health();
    echo "schema_installed: " . (schema_installed() ? 'yes' : 'no') . "\n";
    echo "schema_uptodate:  " . (schema_uptodate() ? 'yes' : 'no') . "\n";
    echo "missing tables:   " . (count($h['missing_tables']) ? implode(', ', $h['missing_tables']) : 'none') . "\n";
    $count = 0;
    foreach ($h['missing_columns'] as $t => $cols) {
        $count += count($cols);
        echo "missing columns:  $t → " . implode(', ', $cols) . "\n";
    }
    if ($count === 0) {
        echo "missing columns:  none\n";
    }
    exit(($h['missing_tables'] || $h['missing_columns']) ? 1 : 0);
}
seed_all(in_array('--reset', $args, true));
if (in_array('--demo', $args, true)) {
    echo json_encode(demo_seed(), JSON_PRETTY_PRINT) . "\n";
}
echo "[seed] completed.\n";
