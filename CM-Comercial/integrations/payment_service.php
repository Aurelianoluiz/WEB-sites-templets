<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';
require_once __DIR__ . '/payment_adapter.php';

/**
 * Creates the internal payment record for an existing order.
 * This is intentionally gateway-neutral: the checkout can call this service
 * before an external provider is selected or while the provider adapter runs.
 */
function create_checkout_payment(PDO $pdo, int $orderId, float $amount, string $method): int
{
    if ($orderId < 1) {
        throw new InvalidArgumentException('Invalid order id.');
    }
    if (!is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException('Invalid payment amount.');
    }
    if ($method === '' || strlen($method) > 40) {
        throw new InvalidArgumentException('Invalid payment method.');
    }

    ensure_payment_schema($pdo);
    return upsert_payment($pdo, $orderId, round($amount, 2), $method);
}

/**
 * Applies a normalized provider event exactly once.
 * Returns true only when this call inserted and applied a new event.
 * An exact duplicate for the same payment returns false so callers can avoid
 * repeating downstream side effects (for example order-state updates).
 * A duplicate event id belonging to another payment is rejected.
 */
function apply_gateway_event(PDO $pdo, int $paymentId, string $eventId, string $eventType, string $status, ?string $transactionId = null, array $payload = []): bool
{
    if ($paymentId < 1 || $eventId === '' || strlen($eventId) > 255) {
        throw new InvalidArgumentException('Invalid payment event.');
    }

    ensure_payment_schema($pdo);
    $pdo->beginTransaction();
    try {
        $inserted = record_payment_event($pdo, $paymentId, $eventId, $eventType, $payload);
        if (!$inserted) {
            $existing = $pdo->prepare('SELECT payment_id FROM payment_events WHERE event_id = ? LIMIT 1');
            $existing->execute([$eventId]);
            $existingPaymentId = $existing->fetchColumn();
            if ((int)$existingPaymentId !== $paymentId) {
                throw new RuntimeException('Payment event id already belongs to another payment.');
            }
            $pdo->commit();
            return false; // exact duplicate webhook; downstream work must not repeat
        }

        if (!transition_payment($pdo, $paymentId, $status, $transactionId)) {
            throw new RuntimeException('Invalid payment transition.');
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
