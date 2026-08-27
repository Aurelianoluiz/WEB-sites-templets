<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/payment_core.php';

/**
 * Returns a privacy-safe customer payment history. The query deliberately
 * excludes provider secrets and raw gateway payloads.
 */
function customer_financial_history(PDO $pdo, int $customerId, int $limit = 50, int $offset = 0): array
{
    if ($customerId < 1) throw new InvalidArgumentException('Invalid customer id.');
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.order_id, p.amount, p.method, p.status, p.transaction_id, p.created_at, p.updated_at
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         WHERE o.customer_id = :customer_id
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
