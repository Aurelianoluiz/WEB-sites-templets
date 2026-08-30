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
        refund_transaction_id TEXT,
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

    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(payments)") as $column) {
        $columns[(string)$column['name']] = true;
    }
    if (!isset($columns['refund_transaction_id'])) {
        $pdo->exec('ALTER TABLE payments ADD COLUMN refund_transaction_id TEXT');
    }
}

function valid_payment_transition(string $from, string $to): bool
{
    if ($from === $to) return false;
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
    if (!is_finite($amount) || $amount <= 0) throw new InvalidArgumentException('Invalid payment amount.');

    ensure_payment_schema($pdo);
    $now = date('c');
    $stmt = $pdo->prepare('SELECT id, status, amount, method FROM payments WHERE order_id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        if ((string)$existing['status'] !== 'pending') return (int)$existing['id'];

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
    if ($paymentId < 1) throw new InvalidArgumentException('Invalid payment id.');
    $eventId = trim($eventId);
    $eventType = trim($eventType);
    if ($eventId === '' || strlen($eventId) > 255) throw new InvalidArgumentException('Invalid payment event id.');
    if ($eventType === '' || strlen($eventType) > 100) throw new InvalidArgumentException('Invalid payment event type.');

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO payment_events (payment_id,event_id,event_type,payload,created_at) VALUES (?,?,?,?,?)');
    $stmt->execute([$paymentId, $eventId, $eventType, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('c')]);
    return $stmt->rowCount() === 1;
}

function transition_payment(PDO $pdo, int $paymentId, string $newStatus, ?string $transactionId = null): bool
{
    $stmt = $pdo->prepare('SELECT status, transaction_id FROM payments WHERE id = ?');
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return false;

    $currentStatus = (string)$row['status'];
    if (!valid_payment_transition($currentStatus, $newStatus)) return false;

    $currentTransactionId = trim((string)($row['transaction_id'] ?? ''));
    $incomingTransactionId = $transactionId === null ? '' : trim($transactionId);
    if ($currentTransactionId !== '' && $incomingTransactionId !== '' && $currentTransactionId !== $incomingTransactionId) return false;

    $sql = 'UPDATE payments SET status = ?, updated_at = ?';
    $params = [$newStatus, date('c')];
    if ($incomingTransactionId !== '') {
        $sql .= ' , transaction_id = ?';
        $params[] = $incomingTransactionId;
    }
    $sql .= ' WHERE id = ? AND status = ?';
    $params[] = $paymentId;
    $params[] = $currentStatus;
    $upd = $pdo->prepare($sql);
    $upd->execute($params);
    return $upd->rowCount() === 1;
}

function record_refund_transaction(PDO $pdo, int $paymentId, string $refundTransactionId): bool
{
    if ($paymentId < 1 || trim($refundTransactionId) === '') throw new InvalidArgumentException('Invalid refund transaction id.');
    ensure_payment_schema($pdo);

    $stmt = $pdo->prepare('SELECT status, refund_transaction_id FROM payments WHERE id=? LIMIT 1');
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false || (string)$row['status'] !== 'refunded') return false;

    $current = trim((string)($row['refund_transaction_id'] ?? ''));
    $incoming = trim($refundTransactionId);
    if ($current !== '' && $current !== $incoming) return false;
    if ($current === $incoming) return true;

    $upd = $pdo->prepare('UPDATE payments SET refund_transaction_id=?, updated_at=? WHERE id=? AND status=\'refunded\' AND (refund_transaction_id IS NULL OR refund_transaction_id=\'\')');
    $upd->execute([$incoming, date('c'), $paymentId]);
    if ($upd->rowCount() === 1) return true;

    $verify = $pdo->prepare('SELECT refund_transaction_id FROM payments WHERE id=? LIMIT 1');
    $verify->execute([$paymentId]);
    return trim((string)$verify->fetchColumn()) === $incoming;
}
