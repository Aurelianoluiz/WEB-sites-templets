<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';

/**
 * Domain-level payment operations. External provider calls must be performed
 * by a gateway adapter; these functions only apply already-confirmed results.
 */
function capture_payment(PDO $pdo, int $paymentId, string $transactionId = ''): bool
{
    if ($paymentId < 1) throw new InvalidArgumentException('Invalid payment id.');
    return transition_payment($pdo, $paymentId, 'paid', $transactionId !== '' ? $transactionId : null);
}

function refund_payment(PDO $pdo, int $paymentId, string $transactionId = ''): bool
{
    if ($paymentId < 1) throw new InvalidArgumentException('Invalid payment id.');
    return transition_payment($pdo, $paymentId, 'refunded', $transactionId !== '' ? $transactionId : null);
}
