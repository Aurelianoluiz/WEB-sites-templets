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

    // Backward-compatible schema upgrade for installations created before
    // reconciliation metadata existed.
    try {
        $columns = $pdo->query('PRAGMA table_info(stock_payment_operations)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn(array $column): string => (string)$column['name'], $columns);
        if (!in_array('reviewed_at', $names, true)) {
            $pdo->exec('ALTER TABLE stock_payment_operations ADD COLUMN reviewed_at TEXT');
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to verify stock operation schema.', 0, $e);
    }
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
        'UPDATE stock_payment_operations SET applied_at=? WHERE operation_key=? AND applied_at IS NULL AND reviewed_at IS NULL'
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

/**
 * Lists claimed operations that still need an explicit reconciliation decision.
 */
function list_unresolved_stock_payment_operations(PDO $pdo): array
{
    ensure_stock_operation_schema($pdo);
    $stmt = $pdo->query(
        'SELECT id, operation_key, order_id, action, created_at, applied_at, reviewed_at
         FROM stock_payment_operations
         WHERE applied_at IS NULL AND reviewed_at IS NULL
         ORDER BY id ASC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Marks an unresolved stock operation as explicitly reviewed by an operator.
 * This does not perform a stock mutation; it only closes the reconciliation
 * record after the operator has verified or repaired the real inventory state.
 */
function mark_stock_payment_operation_reviewed(PDO $pdo, int $orderId, string $action): bool
{
    ensure_stock_operation_schema($pdo);
    $key = stock_operation_key($orderId, $action);
    $stmt = $pdo->prepare(
        'UPDATE stock_payment_operations SET reviewed_at=? WHERE operation_key=? AND applied_at IS NULL AND reviewed_at IS NULL'
    );
    $stmt->execute([date('c'), $key]);
    return $stmt->rowCount() === 1;
}
