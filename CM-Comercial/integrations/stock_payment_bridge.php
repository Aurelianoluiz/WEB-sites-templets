<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/stock_payment.php';

/**
 * Connects payment state changes to the repository-specific inventory layer.
 * The real stock mutation is intentionally injected because this module must
 * not guess the project's inventory schema or silently perform a fake update.
 */
function apply_payment_stock_effect(
    PDO $pdo,
    int $orderId,
    string $paymentStatus,
    callable $mutateStock
): bool {
    $action = stock_action_for_payment($paymentStatus);
    if (in_array($action, ['keep_reservation', 'review_refund_stock'], true)) {
        return false;
    }

    if (!register_stock_payment_operation($pdo, $orderId, $paymentStatus)) {
        return false; // already claimed: never repeat the side effect
    }

    try {
        $mutateStock($pdo, $orderId, $action);
        mark_stock_payment_operation_applied($pdo, $orderId, $action);
        return true;
    } catch (Throwable $e) {
        // Keep the claim so retries remain observable and cannot duplicate a
        // partially-applied inventory mutation. Reconciliation can inspect
        // rows with applied_at IS NULL and repair them explicitly.
        throw $e;
    }
}
