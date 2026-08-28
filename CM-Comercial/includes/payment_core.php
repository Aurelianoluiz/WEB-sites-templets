<?php
declare(strict_types=1);

/**
 * Internal payment domain helpers.
 * Gateway adapters should call these functions instead of changing order
 * status directly. No real gateway credentials are stored here.
 */
function ensure_payment_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        method TEXT,
        transaction_id TEXT,
        amount REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(order_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payment_id INTEGER NOT NULL,
        event_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        payload TEXT,
        created_at TEXT NOT NULL,
        UNIQUE(event_id)
    )");
}

function valid_payment_transition(string $from, string $to): bool
{
    if ($from === $to) return true;
    $allowed = [
        'pending' => ['authorized', 'paid', 'failed', 'cancelled'],
        'authorized' => ['paid', 'failed', 'cancelled'],
        'paid' => ['refunded'],
        'failed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

function upsert_payment(PDO $pdo, int $orderId, float $amount, string $method = 'pending'): int
{
    if ($orderId < 1) throw new InvalidArgumentException('Invalid order id.');
    if ($amount <= 0) throw new InvalidArgumentException('Invalid payment amount.');

    $now = date('c');
    $stmt = $pdo->prepare('SELECT id, status, amount, method FROM payments WHERE order_id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        // A payment that has advanced beyond pending is financially immutable
        // through this setup helper. Further changes must go through the
        // explicit payment state machine/financial operations.
        if ((string)$existing['status'] !== 'pending') {
            return (int)$existing['id'];
        }

        $upd = $pdo->prepare('UPDATE payments SET amount = ?, method = ?, updated_at = ? WHERE id = ? AND status = \'pending\'');
        $upd->execute([$amount, $method, $now, (int)$existing['id']]);
        return (int)$existing['id'];
    }

    $ins = $pdo->prepare('INSERT INTO payments (order_id,status,method,amount,created_at,updated_at) VALUES (?,\'pending\',?,?,?,?)');
    $ins->execute([$orderId, $method, $amount, $now, $now]);
    return (int)$pdo->lastInsertId();
}

function record_payment_event(PDO $pdo, int $paymentId, string $eventId, string $eventType, array $payload = []): bool
{
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO payment_events (payment_id,event_id,event_type,payload,created_at) VALUES (?,?,?,?,?)');
    $stmt->execute([$paymentId, $eventId, $eventType, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('c')]);
    return $stmt->rowCount() === 1;
}

function transition_payment(PDO $pdo, int $paymentId, string $newStatus, ?string $transactionId = null): bool
{
    $stmt = $pdo->prepare('SELECT status FROM payments WHERE id = ?');
    $stmt->execute([$paymentId]);
    $current = $stmt->fetchColumn();
    if ($current === false || !valid_payment_transition((string)$current, $newStatus)) return false;

    $sql = 'UPDATE payments SET status = ?, updated_at = ?';
    $params = [$newStatus, date('c')];
    if ($transactionId !== null) {
        $sql .= ', transaction_id = ?';
        $params[] = $transactionId;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $paymentId;
    $upd = $pdo->prepare($sql);
    $upd->execute($params);
    return $upd->rowCount() === 1;
}
