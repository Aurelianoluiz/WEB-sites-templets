<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/reconciliation_service.php';

$cases = [
    [
        'internal' => ['status' => 'paid', 'amount' => 100.00, 'transaction_id' => 'tx-1'],
        'gateway'  => ['status' => 'paid', 'amount' => 100.00, 'transaction_id' => 'tx-1'],
        'matched'  => true,
    ],
    [
        'internal' => ['status' => 'paid', 'amount' => 100.00, 'transaction_id' => 'tx-1'],
        'gateway'  => ['status' => 'refunded', 'amount' => 100.00, 'transaction_id' => 'tx-1'],
        'matched'  => false,
    ],
    [
        'internal' => ['status' => 'paid', 'amount' => 100.00, 'transaction_id' => ''],
        'gateway'  => ['status' => 'paid', 'amount' => 100.00, 'transaction_id' => 'tx-1'],
        'matched'  => true,
    ],
];

foreach ($cases as $i => $case) {
    $result = reconcile_payment($case['internal'], $case['gateway']);
    if ($result['matched'] !== $case['matched']) {
        fwrite(STDERR, "FAIL: reconciliation case " . ($i + 1) . "\n");
        exit(1);
    }
}

if (!str_contains((string)file_get_contents(__DIR__ . '/../integrations/reconciliation_service.php'), 'No automatic financial mutation')) {
    fwrite(STDERR, "FAIL: reconciliation safety guard documentation missing\n");
    exit(1);
}

echo "PASS: payment reconciliation safety\n";
