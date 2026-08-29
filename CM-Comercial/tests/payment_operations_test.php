<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';
require_once __DIR__ . '/../integrations/payment_operations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$paymentId = upsert_payment($pdo, 10, 199.90, 'pix');
if (!capture_payment($pdo, $paymentId, 'tx-10')) {
    fwrite(STDERR, "FAIL: pending payment should be capturable\n");
    exit(1);
}
if (capture_payment($pdo, $paymentId, 'tx-10')) {
    fwrite(STDERR, "FAIL: paid payment must not be captured twice\n");
    exit(1);
}
if (!refund_payment($pdo, $paymentId, 'refund-10')) {
    fwrite(STDERR, "FAIL: paid payment should be refundable\n");
    exit(1);
}
if (refund_payment($pdo, $paymentId, 'refund-10-2')) {
    fwrite(STDERR, "FAIL: refunded payment must not be refunded twice\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT status, transaction_id, refund_transaction_id FROM payments WHERE id=?');
$stmt->execute([$paymentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (($row['status'] ?? '') !== 'refunded'
    || ($row['transaction_id'] ?? '') !== 'tx-10'
    || ($row['refund_transaction_id'] ?? '') !== 'refund-10') {
    fwrite(STDERR, "FAIL: charge/refund transaction identity mismatch\n");
    exit(1);
}

$paymentId2 = upsert_payment($pdo, 11, 50.00, 'pix');
try {
    capture_payment($pdo, $paymentId2);
    fwrite(STDERR, "FAIL: capture must require transaction id\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

echo "PASS: payment capture/refund operations\n";
