<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/payment_service.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$paymentA = upsert_payment($pdo, 101, 50.00, 'pix');
$paymentB = upsert_payment($pdo, 102, 75.00, 'pix');

if (apply_gateway_event($pdo, $paymentA, 'evt-owner-1', 'payment.created', 'paid', 'tx-a')) {
    // expected success
} else {
    fwrite(STDERR, "FAIL: first gateway event was not accepted\n");
    exit(1);
}

try {
    apply_gateway_event($pdo, $paymentB, 'evt-owner-1', 'payment.created', 'paid', 'tx-b');
    fwrite(STDERR, "FAIL: event id was accepted for another payment\n");
    exit(1);
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'another payment')) {
        fwrite(STDERR, "FAIL: unexpected duplicate event error\n");
        exit(1);
    }
}

echo "PASS: payment event ownership\n";
