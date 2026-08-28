<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/stock_payment_policy.php';
require_once __DIR__ . '/../includes/payment_order_policy.php';

function assert_same_value(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('FAIL: ' . $message . ' (expected ' . $expected . ', got ' . $actual . ')');
    }
}

assert_same_value('review_refund_stock', stock_action_for_payment('refunded'), 'refund must require explicit stock review');
assert_same_value('commit_reservation', stock_action_for_payment('paid'), 'paid must commit reservation');
assert_same_value('release_reservation', stock_action_for_payment('cancelled'), 'cancelled must release reservation');

$decision = payment_order_decision('refunded', 'confirmed');
if ($decision['allowed'] !== true || $decision['action'] !== 'review_refund') {
    throw new RuntimeException('FAIL: refunded payment must enter explicit refund review');
}

$invalid = payment_order_decision('refunded', 'cancelled');
if ($invalid['action'] !== 'review_refund') {
    throw new RuntimeException('FAIL: refund must remain explicit even for cancelled order state');
}

echo "PASS: refund stock policy\n";
