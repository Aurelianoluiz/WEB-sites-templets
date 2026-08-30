<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/payment_service.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$paymentId = create_checkout_payment($pdo, 501, 149.90, 'pix');
$applied = apply_gateway_event(
    $pdo,
    $paymentId,
    'event-status-normalization-1',
    'payment.updated',
    ' PAID ',
    'tx-501',
    ['transaction_amount' => 149.90]
);

if ($applied !== true) {
    fwrite(STDERR, "FAIL: normalized paid event was not applied\n");
    exit(1);
}

$row = $pdo->query('SELECT status, transaction_id FROM payments WHERE id=' . (int)$paymentId)->fetch(PDO::FETCH_ASSOC);
if (($row['status'] ?? '') !== 'paid' || ($row['transaction_id'] ?? '') !== 'tx-501') {
    fwrite(STDERR, "FAIL: normalized status/transaction not persisted\n");
    exit(1);
}

try {
    apply_gateway_event(
        $pdo,
        $paymentId,
        'event-status-normalization-invalid',
        'payment.updated',
        ' unknown ',
        'tx-501',
        []
    );
    fwrite(STDERR, "FAIL: unsupported normalized status was accepted\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

echo "PASS: payment status normalization runtime\n";
