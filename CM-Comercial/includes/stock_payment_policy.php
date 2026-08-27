<?php
declare(strict_types=1);

/**
 * Maps payment events to stock actions. This is a pure policy layer; the
 * repository-specific stock mutation is deliberately injected by the caller.
 */
function stock_action_for_payment(string $paymentStatus): string
{
    return match (strtolower(trim($paymentStatus))) {
        'paid' => 'commit_reservation',
        'failed', 'cancelled' => 'release_reservation',
        'refunded' => 'review_refund_stock',
        default => 'keep_reservation',
    };
}

function stock_operation_key(int $orderId, string $action): string
{
    if ($orderId < 1) throw new InvalidArgumentException('Invalid order id.');
    if ($action === '') throw new InvalidArgumentException('Invalid stock action.');
    return 'order:' . $orderId . ':stock:' . strtolower($action);
}
