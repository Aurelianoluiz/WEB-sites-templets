<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/reconciliation_service.php';

$matched = reconcile_payment(
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-1'],
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-1']
);
if ($matched['matched'] !== true || $matched['differences'] !== []) {
    fwrite(STDERR, "FAIL: exact payment data must reconcile\n");
    exit(1);
}

$amountMismatch = reconcile_payment(
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-1'],
    ['status' => 'paid', 'amount' => 150.00, 'transaction_id' => 'tx-1']
);
if (!in_array('amount', $amountMismatch['differences'], true)) {
    fwrite(STDERR, "FAIL: amount mismatch not detected\n");
    exit(1);
}

$statusMismatch = reconcile_payment(
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-1'],
    ['status' => 'refunded', 'amount' => 149.90, 'transaction_id' => 'tx-1']
);
if (!in_array('status', $statusMismatch['differences'], true)) {
    fwrite(STDERR, "FAIL: status mismatch not detected\n");
    exit(1);
}

$txMismatch = reconcile_payment(
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-1'],
    ['status' => 'paid', 'amount' => 149.90, 'transaction_id' => 'tx-2']
);
if (!in_array('transaction_id', $txMismatch['differences'], true)) {
    fwrite(STDERR, "FAIL: transaction mismatch not detected\n");
    exit(1);
}

echo "PASS: payment consistency reconciliation\n";
