<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';

$valid = [
    ['pending', 'authorized'],
    ['pending', 'paid'],
    ['pending', 'failed'],
    ['pending', 'cancelled'],
    ['authorized', 'paid'],
    ['authorized', 'failed'],
    ['authorized', 'cancelled'],
    ['paid', 'refunded'],
];

$invalid = [
    ['failed', 'paid'],
    ['cancelled', 'paid'],
    ['refunded', 'paid'],
    ['paid', 'cancelled'],
    ['authorized', 'pending'],
];

foreach ($valid as [$from, $to]) {
    if (!valid_payment_transition($from, $to)) {
        fwrite(STDERR, "FAIL valid transition: {$from} -> {$to}\n");
        exit(1);
    }
}

foreach ($invalid as [$from, $to]) {
    if (valid_payment_transition($from, $to)) {
        fwrite(STDERR, "FAIL invalid transition accepted: {$from} -> {$to}\n");
        exit(1);
    }
}

echo "PASS: payment state transition checks\n";
