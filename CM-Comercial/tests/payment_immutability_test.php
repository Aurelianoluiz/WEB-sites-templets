<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$paymentId = upsert_payment($pdo, 100, 149.90, 'pix');
if ($paymentId < 1) {
    fwrite(STDERR, "FAIL: payment was not created\n");
    exit(1);
}

if (!transition_payment($pdo, $paymentId, 'paid', 'tx-100')) {
    fwrite(STDERR, "FAIL: payment did not transition to paid\n");
    exit(1);
}

$sameId = upsert_payment($pdo, 100, 999.99, 'credit_card');
if ($sameId !== $paymentId) {
    fwrite(STDERR, "FAIL: immutable payment identity changed\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT amount, method, status, transaction_id FROM payments WHERE id=?');
$stmt->execute([$paymentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ((float)$row['amount'] !== 149.90 || $row['method'] !== 'pix' || $row['status'] !== 'paid' || $row['transaction_id'] !== 'tx-100') {
    fwrite(STDERR, "FAIL: payment changed after reaching paid state\n");
    exit(1);
}

echo "PASS: paid payment is immutable through upsert\n";
