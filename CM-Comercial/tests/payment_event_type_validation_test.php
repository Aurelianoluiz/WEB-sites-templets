<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/payment_service.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);
$paymentId = upsert_payment($pdo, 901, 79.90, 'pix');

try {
    apply_gateway_event($pdo, $paymentId, 'evt-invalid-type', '   ', 'paid', 'tx-901');
    fwrite(STDERR, "FAIL: empty event type was accepted\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

if (!apply_gateway_event($pdo, $paymentId, 'evt-valid-type', 'payment.updated', 'paid', 'tx-901')) {
    fwrite(STDERR, "FAIL: valid event was not applied\n");
    exit(1);
}

if (apply_gateway_event($pdo, $paymentId, 'evt-valid-type', 'payment.updated', 'paid', 'tx-901') !== false) {
    fwrite(STDERR, "FAIL: duplicate event should be idempotent\n");
    exit(1);
}

echo "PASS: payment event type validation\n";
