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
if (!refund_payment($pdo, $paymentId, 'tx-10-refund')) {
    fwrite(STDERR, "FAIL: paid payment should be refundable\n");
    exit(1);
}
if (refund_payment($pdo, $paymentId, 'tx-10-refund-2')) {
    fwrite(STDERR, "FAIL: refunded payment must not be refunded twice\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT status, transaction_id FROM payments WHERE id=?');
$stmt->execute([$paymentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (($row['status'] ?? '') !== 'refunded' || ($row['transaction_id'] ?? '') !== 'tx-10-refund') {
    fwrite(STDERR, "FAIL: refund state/transaction mismatch\n");
    exit(1);
}

echo "PASS: payment capture/refund operations\n";
