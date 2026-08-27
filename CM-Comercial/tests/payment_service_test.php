<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

$cases = [
    ['pending', 'authorized', true],
    ['pending', 'paid', true],
    ['pending', 'failed', true],
    ['pending', 'cancelled', true],
    ['authorized', 'paid', true],
    ['authorized', 'failed', true],
    ['authorized', 'cancelled', true],
    ['paid', 'refunded', true],
    ['paid', 'pending', false],
    ['failed', 'paid', false],
    ['cancelled', 'paid', false],
    ['refunded', 'paid', false],
];

foreach ($cases as [$from, $to, $expected]) {
    assert_true(valid_payment_transition($from, $to) === $expected, "$from -> $to");
}

// In-memory SQLite validates the event ledger's UNIQUE(event_id) behavior.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);
$paymentId = upsert_payment($pdo, 1, 199.90, 'pix');
assert_true(record_payment_event($pdo, $paymentId, 'evt-test-1', 'payment.created', ['amount' => 199.90]) === true, 'first event accepted');
assert_true(record_payment_event($pdo, $paymentId, 'evt-test-1', 'payment.created', ['amount' => 199.90]) === false, 'duplicate event rejected');
assert_true(transition_payment($pdo, $paymentId, 'paid', 'txn-test-1') === true, 'pending -> paid');
assert_true(transition_payment($pdo, $paymentId, 'pending') === false, 'paid -> pending rejected');

echo "PASS: payment service/idempotency tests\n";
