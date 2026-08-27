<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';
require_once __DIR__ . '/../integrations/payment_operations.php';
require_once __DIR__ . '/../integrations/reconciliation_service.php';
require_once __DIR__ . '/../includes/stock_payment_policy.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY, order_id INTEGER, amount REAL, method TEXT, status TEXT, transaction_id TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("INSERT INTO payments VALUES (1, 100, 149.90, 'pix', 'pending', NULL, '2026-08-27 12:00:00', '2026-08-27 12:00:00')");

assert_true(capture_payment($pdo, 1, 'tx-100'), 'capture should transition pending to paid');
assert_true(stock_action_for_payment('paid') === 'commit_reservation', 'paid must commit stock reservation');
assert_true(stock_action_for_payment('failed') === 'release_reservation', 'failed must release reservation');
assert_true(stock_operation_key(100, 'commit_reservation') === 'order:100:stock:commit_reservation', 'stock operation key must be deterministic');

$check = reconcile_payment(
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-100'],
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-100']
);
assert_true($check['matched'] === true, 'matching gateway data must reconcile');

$check = reconcile_payment(
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-100'],
    ['status' => 'refunded', 'amount' => 149.90, 'transaction_id' => 'tx-100']
);
assert_true(in_array('status', $check['differences'], true), 'status divergence must be reported');

echo "PASS: integration suite\n";
