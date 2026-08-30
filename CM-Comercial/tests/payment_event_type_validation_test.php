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

echo "PASS: payment event type validation\n";
