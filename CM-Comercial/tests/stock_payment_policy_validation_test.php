<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/stock_payment_policy.php';

$expected = [
    'pending' => 'keep_reservation',
    'authorized' => 'keep_reservation',
    'paid' => 'commit_reservation',
    'failed' => 'release_reservation',
    'cancelled' => 'release_reservation',
    'refunded' => 'review_refund_stock',
];

foreach ($expected as $status => $action) {
    if (stock_action_for_payment($status) !== $action) {
        fwrite(STDERR, "FAIL: unexpected stock action for $status\n");
        exit(1);
    }
}

try {
    stock_action_for_payment('gateway_pending_unknown');
    fwrite(STDERR, "FAIL: unsupported payment status was silently accepted\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

echo "PASS: stock payment policy validation\n";
