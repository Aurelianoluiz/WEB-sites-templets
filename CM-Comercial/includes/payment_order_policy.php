<?php
declare(strict_types=1);

/**
 * Central policy for keeping financial and order states consistent.
 * This module is intentionally side-effect free; callers should apply the
 * returned decision inside their existing database transaction.
 */
function payment_order_decision(string $paymentStatus, string $orderStatus): array
{
    $paymentStatus = strtolower(trim($paymentStatus));
    $orderStatus = strtolower(trim($orderStatus));

    if ($paymentStatus === 'paid' && in_array($orderStatus, ['pending', 'confirmed'], true)) {
        return ['allowed' => true, 'action' => 'confirm_order', 'reason' => 'Pagamento confirmado.'];
    }

    if ($paymentStatus === 'failed' && in_array($orderStatus, ['pending', 'confirmed'], true)) {
        return ['allowed' => true, 'action' => 'keep_order_pending', 'reason' => 'Pagamento recusado; pedido não deve avançar.'];
    }

    if ($paymentStatus === 'cancelled' && in_array($orderStatus, ['pending', 'confirmed'], true)) {
        return ['allowed' => true, 'action' => 'cancel_order', 'reason' => 'Pagamento cancelado.'];
    }

    if ($paymentStatus === 'refunded') {
        return ['allowed' => true, 'action' => 'review_refund', 'reason' => 'Estorno exige tratamento de pedido/estoque conforme a política de negócio.'];
    }

    return ['allowed' => false, 'action' => 'no_change', 'reason' => 'Nenhuma transição automática aplicável.'];
}
