<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class OrderRepository implements OrderRepositoryInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findById(int $id, bool $forUpdate = false): ?array
    {
        try {
            $sql = 'SELECT o.*, u.name AS user_name, u.email AS user_email
                    FROM orders o
                    LEFT JOIN users u ON u.id = o.user_id
                    WHERE o.id = ? LIMIT 1';
            if ($forUpdate) {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function findByReference(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        try {
            if (ctype_digit($ref)) {
                $row = $this->findById((int)$ref);
                if ($row !== null) {
                    return $row;
                }
            }

            $stmt = $this->db->prepare(
                'SELECT o.*, u.name AS user_name, u.email AS user_email
                 FROM orders o
                 LEFT JOIN users u ON u.id = o.user_id
                 WHERE o.idempotency_key = ? LIMIT 1'
            );
            $stmt->execute([$ref]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function findByIdAndUser(int $id, int $userId, bool $forUpdate = false): ?array
    {
        try {
            $sql = 'SELECT o.*, u.name AS user_name, u.email AS user_email
                    FROM orders o
                    INNER JOIN users u ON u.id = o.user_id
                    WHERE o.id = ? AND o.user_id = ? LIMIT 1';
            if ($forUpdate) {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function findByUserId(int $userId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        try {
            $stmt = $this->db->prepare(
                'SELECT o.* FROM orders o
                 WHERE o.user_id = ?
                 ORDER BY o.id DESC LIMIT ? OFFSET ?'
            );
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function findItemsByOrderId(int $orderId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT oi.*, p.name
                 FROM order_items oi
                 INNER JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = ? ORDER BY oi.id'
            );
            $stmt->execute([$orderId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function updateStatus(int $id, string $status, ?string $paymentStatus = null): bool
    {
        try {
            if ($paymentStatus !== null) {
                $stmt = $this->db->prepare('UPDATE orders SET status = ?, payment_status = ? WHERE id = ?');
                $stmt->execute([$status, $paymentStatus, $id]);
                return $stmt->rowCount() >= 0;
            }

            $stmt = $this->db->prepare('UPDATE orders SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            return $stmt->rowCount() >= 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordStatusHistory(int $orderId, string $from, string $to, ?int $actorUserId, string $note = ''): bool
    {
        if ($from === $to) {
            return true;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO order_status_history (order_id, from_status, to_status, actor_user_id, note)
                 VALUES (?, ?, ?, ?, ?)'
            );
            return $stmt->execute([$orderId, $from, $to, $actorUserId, $note]);
        } catch (Throwable) {
            return false;
        }
    }

    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $conditions = [];
        $params = [];

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'o.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['payment_status']) && is_string($filters['payment_status']) && $filters['payment_status'] !== '') {
            $conditions[] = 'o.payment_status = ?';
            $params[] = $filters['payment_status'];
        }
        if (isset($filters['user_id']) && is_numeric($filters['user_id'])) {
            $conditions[] = 'o.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $conditions[] = '(o.customer_name LIKE ? OR o.email LIKE ? OR CAST(o.id AS CHAR) LIKE ?)';
            $term = '%' . trim($filters['search']) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql = 'SELECT o.*, u.email AS user_email
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY o.id DESC LIMIT ? OFFSET ?';

        try {
            $stmt = $this->db->prepare($sql);
            $position = 1;
            foreach ($params as $param) {
                $stmt->bindValue($position++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($position, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function listAll(string $statusFilter = '', int $limit = 50, int $offset = 0): array
    {
        return $this->listWithFilters($statusFilter === '' ? [] : ['status' => $statusFilter], $limit, $offset);
    }
}
