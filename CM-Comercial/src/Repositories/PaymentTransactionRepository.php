<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class PaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $conditions = [];
        $params = [];

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, ['pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded'], true)) {
            $conditions[] = 'p.status = ?';
            $params[] = $status;
        }

        $provider = $filters['provider'] ?? null;
        if (is_string($provider) && trim($provider) !== '') {
            $conditions[] = 'p.provider = ?';
            $params[] = trim($provider);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $conditions[] = '(CAST(p.id AS CHAR) LIKE ? OR CAST(p.order_id AS CHAR) LIKE ? OR p.transaction_id LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql = 'SELECT p.*, o.status AS order_status, o.total AS order_total,
                       o.customer_name, o.email AS order_email
                FROM payments p
                LEFT JOIN orders o ON o.id = p.order_id';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY p.id DESC LIMIT ? OFFSET ?';

        try {
            $stmt = $this->db->prepare($sql);
            $position = 1;
            foreach ($params as $param) {
                $stmt->bindValue($position++, $param, PDO::PARAM_STR);
            }
            $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($position, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
