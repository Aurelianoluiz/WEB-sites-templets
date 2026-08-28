<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$paymentId = upsert_payment($pdo, 301, 100.00, 'pix');
if (!transition_payment($pdo, $paymentId, 'paid', 'tx-301')) {
    fwrite(STDERR, "FAIL: initial transaction binding\n");
    exit(1);
}

if (transition_payment($pdo, $paymentId, 'refunded', 'tx-301') !== true) {
    fwrite(STDERR, "FAIL: matching transaction id was rejected\n");
    exit(1);
}

$paymentId2 = upsert_payment($pdo, 302, 120.00, 'pix');
if (!transition_payment($pdo, $paymentId2, 'paid', 'tx-302-a')) {
    fwrite(STDERR, "FAIL: second initial transaction binding\n");
    exit(1);
}

if (transition_payment($pdo, $paymentId2, 'refunded', 'tx-302-b') !== false) {
    fwrite(STDERR, "FAIL: transaction id was replaced during transition\n");
    exit(1);
}

echo "PASS: payment transaction identity\n";
