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
    if ($transactionId === '') throw new InvalidArgumentException('Capture transaction id is required.');
    return transition_payment($pdo, $paymentId, 'paid', $transactionId);
}

function refund_payment(PDO $pdo, int $paymentId, string $transactionId = ''): bool
{
    if ($paymentId < 1) throw new InvalidArgumentException('Invalid payment id.');
    if ($transactionId === '') throw new InvalidArgumentException('Refund transaction id is required.');

    if (!transition_payment($pdo, $paymentId, 'refunded', null)) return false;
    return record_refund_transaction($pdo, $paymentId, $transactionId);
}
