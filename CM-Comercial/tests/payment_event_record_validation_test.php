<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);
$paymentId = upsert_payment($pdo, 902, 89.90, 'pix');

$cases = [
    ['eventId' => '', 'eventType' => 'payment.created'],
    ['eventId' => 'evt-ok', 'eventType' => '   '],
];

foreach ($cases as $case) {
    try {
        record_payment_event($pdo, $paymentId, $case['eventId'], $case['eventType']);
        fwrite(STDERR, "FAIL: invalid event was accepted\n");
        exit(1);
    } catch (InvalidArgumentException) {
        // expected
    }
}

if (!record_payment_event($pdo, $paymentId, 'evt-valid', 'payment.created', ['ok' => true])) {
    fwrite(STDERR, "FAIL: valid event was rejected\n");
    exit(1);
}

if (record_payment_event($pdo, $paymentId, 'evt-valid', 'payment.created', ['ok' => true])) {
    fwrite(STDERR, "FAIL: duplicate event was accepted\n");
    exit(1);
}

echo "PASS: payment event record validation\n";
