<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';
require_once __DIR__ . '/../integrations/payment_operations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$paymentId = upsert_payment($pdo, 301, 100.00, 'pix');
if (!transition_payment($pdo, $paymentId, 'paid', 'tx-301')) {
    fwrite(STDERR, "FAIL: initial transaction binding\n");
    exit(1);
}

if (!refund_payment($pdo, $paymentId, 'refund-301')) {
    fwrite(STDERR, "FAIL: refund operation was rejected\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT status, transaction_id, refund_transaction_id FROM payments WHERE id=?');
$stmt->execute([$paymentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (($row['status'] ?? '') !== 'refunded'
    || ($row['transaction_id'] ?? '') !== 'tx-301'
    || ($row['refund_transaction_id'] ?? '') !== 'refund-301') {
    fwrite(STDERR, "FAIL: original/refund transaction identities were not preserved\n");
    exit(1);
}

if (refund_payment($pdo, $paymentId, 'refund-301-2')) {
    fwrite(STDERR, "FAIL: refunded payment accepted a second refund\n");
    exit(1);
}

$paymentId2 = upsert_payment($pdo, 302, 120.00, 'pix');
if (!transition_payment($pdo, $paymentId2, 'paid', 'tx-302-a')) {
    fwrite(STDERR, "FAIL: second initial transaction binding\n");
    exit(1);
}

if (transition_payment($pdo, $paymentId2, 'refunded', 'tx-302-b') !== false) {
    fwrite(STDERR, "FAIL: transition path replaced the original transaction id\n");
    exit(1);
}

echo "PASS: payment transaction identity\n";
