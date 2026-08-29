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

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        if (!transition_payment($pdo, $paymentId, 'refunded', null)) {
            if ($startedTransaction) $pdo->commit();
            return false;
        }

        if (!record_refund_transaction($pdo, $paymentId, $transactionId)) {
            throw new RuntimeException('Unable to record refund transaction.');
        }

        if ($startedTransaction) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
