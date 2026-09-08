<?php
/**
 * End-to-end lifecycle smoke test.
 *
 * This test intentionally runs against the configured database. For local-safe
 * smoke testing use SQLite, for example:
 *
 *   DB_DRIVER=sqlite DB_SQLITE_FILE=/tmp/gp-e2e/app.db \
 *     php tests/e2e_lifecycle.php
 *
 * It creates only clearly-labelled [DEMO] records and verifies that the major
 * workflow stages are backed by real database rows and generated PDFs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/schema.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/notifications.php';
require_once __DIR__ . '/../app/workflow.php';
require_once __DIR__ . '/../app/compliance.php';
require_once __DIR__ . '/../app/services.php';
require_once __DIR__ . '/../app/documents.php';
require_once __DIR__ . '/../app/backup.php';
require_once __DIR__ . '/../app/seed.php';

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function one(string $sql, array $params = []): array
{
    $row = DB::one($sql, $params);
    ok((bool) $row, 'Missing expected row for: ' . $sql);
    return $row;
}

function pdf_ok(array $doc): array
{
    $path = document_file_path($doc);
    ok($path !== null, 'Generated document file missing for doc #' . ($doc['id'] ?? '?'));
    $head = file_get_contents($path, false, null, 0, 8);
    ok(str_starts_with((string) $head, '%PDF-'), 'Generated file is not a PDF: ' . $path);
    ok(filesize($path) > 200, 'Generated PDF is unexpectedly small: ' . $path);
    return ['id' => (int) $doc['id'], 'category' => $doc['category'], 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
}

if (DB_DRIVER === 'mysql' && getenv('ALLOW_E2E_MYSQL_RESET') !== '1') {
    fwrite(STDERR, "Refusing to reset a MySQL database unless ALLOW_E2E_MYSQL_RESET=1 is set. Use SQLite for routine smoke tests.\n");
    exit(2);
}

seed_all(true, ['email' => 'admin@panchayat.local', 'name' => 'Smoke Admin', 'password' => 'TestAdmin123']);
$seed = demo_seed();
$actor = one("SELECT * FROM users WHERE email = 'admin@panchayat.local'");
$tender = one("SELECT * FROM tenders WHERE work_name LIKE '[DEMO]%' ORDER BY id DESC LIMIT 1");
$project = one('SELECT * FROM projects WHERE id = ?', [(int) $tender['project_id']]);
$award = one('SELECT * FROM awards WHERE tender_id = ? ORDER BY id DESC LIMIT 1', [(int) $tender['id']]);
$agreement = one('SELECT * FROM agreements WHERE award_id = ? ORDER BY id DESC LIMIT 1', [(int) $award['id']]);
$workOrder = one('SELECT * FROM work_orders WHERE award_id = ? ORDER BY id DESC LIMIT 1', [(int) $award['id']]);
$measurement = one('SELECT * FROM measurements WHERE project_id = ? ORDER BY id DESC LIMIT 1', [(int) $project['id']]);
$bill = one('SELECT * FROM bills WHERE project_id = ? ORDER BY id DESC LIMIT 1', [(int) $project['id']]);
$payment = one('SELECT * FROM payments WHERE bill_id = ? ORDER BY id DESC LIMIT 1', [(int) $bill['id']]);
$completion = one('SELECT * FROM completions WHERE project_id = ? ORDER BY id DESC LIMIT 1', [(int) $project['id']]);

ok($project['status'] === 'closed', 'Project did not close.');
ok($completion['status'] === 'closed', 'Completion workflow did not close.');
ok((int) DB::val('SELECT COUNT(*) FROM boq_items WHERE tender_id = ?', [(int) $tender['id']]) >= 1, 'BOQ rows missing.');
ok((int) DB::val('SELECT COUNT(*) FROM tender_bidders WHERE tender_id = ?', [(int) $tender['id']]) >= 2, 'Bidder rows missing.');
ok((int) DB::val('SELECT COUNT(*) FROM technical_evaluations WHERE tender_id = ?', [(int) $tender['id']]) >= 2, 'Technical evaluation rows missing.');
ok((int) DB::val('SELECT COUNT(*) FROM financial_bids WHERE tender_id = ?', [(int) $tender['id']]) >= 1, 'Financial bid rows missing.');
ok((int) $payment['net_amount_minor'] <= (int) $bill['net_payable_minor'], 'Payment exceeds bill net payable.');

// Generate the acceptance-contract PDF set that is not already created by the demo seed.
$generated = [];
$generated[] = pdf_ok(document_generate_tender_document((int) $tender['id'], $actor));
$generated[] = pdf_ok(document_generate_boq((int) $tender['id'], $actor));
$generated[] = pdf_ok(document_generate_technical_evaluation((int) $tender['id'], $actor));
$generated[] = pdf_ok(document_generate_comparative((int) $tender['id'], $actor));
$generated[] = pdf_ok(document_generate_bill((int) $bill['id'], $actor));
$generated[] = pdf_ok(document_generate_complete_tender_file((int) $tender['id'], $actor));

$requiredCategories = ['nit', 'tender_document', 'boq', 'technical_evaluation', 'comparative_statement', 'loa', 'agreement', 'work_order', 'bill', 'completion_certificate', 'complete_tender_file'];
$categoryResults = [];
foreach ($requiredCategories as $category) {
    $doc = DB::one('SELECT * FROM documents WHERE category = ? ORDER BY id DESC LIMIT 1', [$category]);
    ok((bool) $doc, 'Required generated document category missing: ' . $category);
    $categoryResults[$category] = pdf_ok($doc);
}

$requiredAudit = ['auth.login_failed', 'tender.create', 'tender.submit', 'nit.generate', 'tender.publish', 'bid.record', 'bid.technical_open', 'evaluation.technical_finalize', 'evaluation.financial_record', 'evaluation.rank', 'award.recommend', 'award.approve', 'award.loa', 'agreement.create', 'work_order.issue', 'measurement.create', 'measurement.item_add', 'bill.create', 'bill.submit', 'payment.record', 'completion.closed', 'document.generate'];
// demo_seed is CLI and does not perform login; create one failed auth event to prove auth auditing.
try { login_user('admin@panchayat.local', 'wrong-password'); } catch (Throwable $ignore) {}
foreach ($requiredAudit as $action) {
    ok((int) DB::val('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]) > 0, 'Required audit event missing: ' . $action);
}

$out = [
    'status' => 'PASS',
    'driver' => DB_DRIVER,
    'schema_version' => schema_version(),
    'seed' => $seed,
    'tender' => ['id' => (int) $tender['id'], 'number' => $tender['tender_number'], 'status' => DB::val('SELECT status FROM tenders WHERE id = ?', [(int) $tender['id']])],
    'project' => ['id' => (int) $project['id'], 'status' => DB::val('SELECT status FROM projects WHERE id = ?', [(int) $project['id']])],
    'award' => ['id' => (int) $award['id'], 'status' => $award['status'], 'amount' => from_minor($award['awarded_amount_minor'])],
    'agreement' => $agreement['agreement_number'],
    'work_order' => $workOrder['work_order_number'],
    'measurement' => $measurement['measurement_number'],
    'bill' => ['number' => $bill['bill_number'], 'status' => DB::val('SELECT status FROM bills WHERE id = ?', [(int) $bill['id']]), 'net' => from_minor($bill['net_payable_minor'])],
    'payment' => ['voucher' => $payment['voucher_no'], 'net' => from_minor($payment['net_amount_minor'])],
    'completion_status' => $completion['status'],
    'documents' => $categoryResults,
    'audit_count' => (int) DB::val('SELECT COUNT(*) FROM audit_logs'),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
