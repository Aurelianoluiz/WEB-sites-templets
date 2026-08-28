<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_payment_policy.php';

/**
 * Idempotency ledger for stock side effects driven by payment state.
 * The actual inventory mutation is intentionally injected by the caller.
 */
function ensure_stock_operation_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_payment_operations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        operation_key TEXT NOT NULL UNIQUE,
        order_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        created_at TEXT NOT NULL,
        applied_at TEXT,
        UNIQUE(order_id, action)
    )");
}

/**
 * Atomically claims a stock side effect for a payment status.
 * A true return means this caller owns the first claim and may perform the
 * real stock mutation. A false return means the operation was already claimed.
 */
function register_stock_payment_operation(PDO $pdo, int $orderId, string $paymentStatus): bool
{
    $action = stock_action_for_payment($paymentStatus);
    if ($orderId < 1) throw new InvalidArgumentException('Invalid order id.');
    if ($action === 'keep_reservation' || $action === 'review_refund_stock') {
        return false;
    }

    ensure_stock_operation_schema($pdo);
    $key = stock_operation_key($orderId, $action);
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO stock_payment_operations (operation_key, order_id, action, created_at) VALUES (?,?,?,?)'
    );
    $stmt->execute([$key, $orderId, $action, date('c')]);
    return $stmt->rowCount() === 1;
}

/**
 * Marks a previously claimed operation as applied. This is intentionally
 * conditional so repeated callbacks cannot mark an already-applied effect.
 */
function mark_stock_payment_operation_applied(PDO $pdo, int $orderId, string $action): bool
{
    ensure_stock_operation_schema($pdo);
    $key = stock_operation_key($orderId, $action);
    $stmt = $pdo->prepare(
        'UPDATE stock_payment_operations SET applied_at=? WHERE operation_key=? AND applied_at IS NULL'
    );
    $stmt->execute([date('c'), $key]);
    return $stmt->rowCount() === 1;
}

/**
 * Returns whether the operation was already claimed/applied.
 */
function stock_payment_operation_claimed(PDO $pdo, int $orderId, string $action): bool
{
    ensure_stock_operation_schema($pdo);
    $key = stock_operation_key($orderId, $action);
    $stmt = $pdo->prepare('SELECT 1 FROM stock_payment_operations WHERE operation_key=? LIMIT 1');
    $stmt->execute([$key]);
    return $stmt->fetchColumn() !== false;
}
