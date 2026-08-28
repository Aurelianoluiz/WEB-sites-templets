<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/payment_service.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$paymentId = upsert_payment($pdo, 401, 100.00, 'pix');

if (apply_gateway_event($pdo, $paymentId, 'evt-duplicate-1', 'payment', 'paid', 'tx-401') !== true) {
    fwrite(STDERR, "FAIL: first event was not applied\n");
    exit(1);
}

if (apply_gateway_event($pdo, $paymentId, 'evt-duplicate-1', 'payment', 'paid', 'tx-401') !== false) {
    fwrite(STDERR, "FAIL: exact duplicate did not return false\n");
    exit(1);
}

$status = $pdo->query('SELECT status FROM payments WHERE id=' . (int)$paymentId)->fetchColumn();
if ($status !== 'paid') {
    fwrite(STDERR, "FAIL: duplicate changed payment state\n");
    exit(1);
}

echo "PASS: exact duplicate webhook skips downstream work\n";
