<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class PaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    private const STATUSES = [
        'pending',
        'authorized',
        'paid',
        'failed',
        'cancelled',
        'refunded',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string, scalar|null> $filters */
    private function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $conditions[] = 'p.status = ?';
            $params[] = $status;
        }

        $provider = $filters['provider'] ?? null;
        if (is_string($provider) && trim($provider) !== '') {
            $conditions[] = 'p.provider = ?';
            $params[] = trim($provider);
        }

        $customerId = $filters['customer_id'] ?? null;
        if (is_numeric($customerId) && (int)$customerId > 0) {
            $conditions[] = 'o.user_id = ?';
            $params[] = (int)$customerId;
        }

        $orderId = $filters['order_id'] ?? null;
        if (is_numeric($orderId) && (int)$orderId > 0) {
            $conditions[] = 'p.order_id = ?';
            $params[] = (int)$orderId;
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        if ($dateFrom !== null) {
            $conditions[] = 'p.created_at >= ?';
            $params[] = $dateFrom;
        }

        $dateTo = $this->normalizeDate($filters['date_to'] ?? null, true);
        if ($dateTo !== null) {
            $conditions[] = 'p.created_at <= ?';
            $params[] = $dateTo;
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $conditions[] = '(CAST(p.id AS CHAR) LIKE ? OR CAST(p.order_id AS CHAR) LIKE ? OR p.transaction_id LIKE ? OR o.email LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        return [$conditions, $params];
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return list<array<string, mixed>>
     */
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$conditions, $params] = $this->buildFilters($filters);

        $sql = 'SELECT p.*, o.status AS order_status, o.payment_status AS order_payment_status,
                       o.total AS order_total, o.customer_name, o.email AS order_email
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

    /** @param array<string, scalar|null> $filters */
    public function summarize(array $filters = []): array
    {
        [$conditions, $params] = $this->buildFilters($filters);
        $sql = 'SELECT
                    COUNT(*) AS total_count,
                    COALESCE(SUM(p.amount), 0) AS gross_total,
                    COALESCE(SUM(CASE WHEN p.status = \'paid\' THEN p.amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN p.status = \'refunded\' THEN p.amount ELSE 0 END), 0) AS refunded_total,
                    COALESCE(SUM(CASE WHEN p.status = \'pending\' THEN p.amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN p.status = \'failed\' THEN p.amount ELSE 0 END), 0) AS failed_total,
                    COALESCE(SUM(CASE WHEN p.status = \'cancelled\' THEN p.amount ELSE 0 END), 0) AS cancelled_total,
                    COALESCE(SUM(CASE WHEN p.status = \'authorized\' THEN p.amount ELSE 0 END), 0) AS authorized_total
                FROM payments p
                LEFT JOIN orders o ON o.id = p.order_id';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'count' => (int)($row['total_count'] ?? 0),
                'total' => round((float)($row['gross_total'] ?? 0), 2),
                'paid' => round((float)($row['paid_total'] ?? 0), 2),
                'refunded' => round((float)($row['refunded_total'] ?? 0), 2),
                'pending' => round((float)($row['pending_total'] ?? 0), 2),
                'failed' => round((float)($row['failed_total'] ?? 0), 2),
                'cancelled' => round((float)($row['cancelled_total'] ?? 0), 2),
                'authorized' => round((float)($row['authorized_total'] ?? 0), 2),
            ];
        } catch (Throwable) {
            return [
                'count' => 0,
                'total' => 0.0,
                'paid' => 0.0,
                'refunded' => 0.0,
                'pending' => 0.0,
                'failed' => 0.0,
                'cancelled' => 0.0,
                'authorized' => 0.0,
            ];
        }
    }

    /**
     * Returns database-level reconciliation counters without loading every row.
     * @param array<string, scalar|null> $filters
     * @return array<string,int|float>
     */
    public function summarizeForReconciliation(array $filters = []): array
    {
        [$conditions, $params] = $this->buildFilters($filters);
        $sql = 'SELECT
                    COUNT(*) AS total_count,
                    COALESCE(SUM(p.amount), 0) AS total_amount,
                    SUM(CASE
                        WHEN o.id IS NULL THEN 1
                        WHEN ABS(COALESCE(p.amount,0) - COALESCE(o.total,0)) > 0.01 THEN 0
                        WHEN p.status = \'paid\' AND o.status = \'cancelled\' THEN 0
                        WHEN o.payment_status IS NOT NULL AND o.payment_status <> p.status
                             AND NOT (p.status = \'authorized\' AND o.payment_status IN (\'authorized\',\'pending\'))
                             AND NOT (p.status = \'cancelled\' AND o.payment_status IN (\'cancelled\',\'failed\'))
                             THEN 0
                        WHEN p.status IN (\'pending\',\'authorized\') THEN 0
                        ELSE 1
                    END) AS reconciled_count,
                    SUM(CASE
                        WHEN o.id IS NOT NULL
                             AND ABS(COALESCE(p.amount,0) - COALESCE(o.total,0)) > 0.01 THEN 1
                        ELSE 0
                    END) AS amount_mismatch_count,
                    SUM(CASE
                        WHEN o.id IS NOT NULL
                             AND ABS(COALESCE(p.amount,0) - COALESCE(o.total,0)) <= 0.01
                             AND (
                                 (p.status = \'paid\' AND o.status = \'cancelled\')
                                 OR (
                                     o.payment_status IS NOT NULL
                                     AND o.payment_status <> p.status
                                     AND NOT (p.status = \'authorized\' AND o.payment_status IN (\'authorized\',\'pending\'))
                                     AND NOT (p.status = \'cancelled\' AND o.payment_status IN (\'cancelled\',\'failed\'))
                                 )
                             ) THEN 1
                        ELSE 0
                    END) AS status_mismatch_count,
                    SUM(CASE WHEN o.id IS NULL THEN 1 ELSE 0 END) AS orphan_count,
                    SUM(CASE
                        WHEN o.id IS NOT NULL
                             AND ABS(COALESCE(p.amount,0) - COALESCE(o.total,0)) <= 0.01
                             AND o.payment_status IS NOT NULL
                             AND p.status IN (\'pending\',\'authorized\')
                             AND o.payment_status IN (\'pending\',\'authorized\')
                             THEN 1
                        ELSE 0
                    END) AS pending_count
                FROM payments p
                LEFT JOIN orders o ON o.id = p.order_id';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $total = (int)($row['total_count'] ?? 0);
            $orphan = (int)($row['orphan_count'] ?? 0);
            $amountMismatch = (int)($row['amount_mismatch_count'] ?? 0);
            $statusMismatch = (int)($row['status_mismatch_count'] ?? 0);
            $pending = (int)($row['pending_count'] ?? 0);
            $reconciled = max(0, $total - $orphan - $amountMismatch - $statusMismatch - $pending);
            $divergent = $amountMismatch + $statusMismatch;
            $inconsistent = $orphan;

            return [
                'total' => $total,
                'reconciled' => $reconciled,
                'divergent' => $divergent,
                'pending' => $pending,
                'inconsistent' => $inconsistent,
                'orphan_transactions' => $orphan,
                'amount_mismatches' => $amountMismatch,
                'status_mismatches' => $statusMismatch,
                'total_amount' => round((float)($row['total_amount'] ?? 0), 2),
            ];
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to summarize reconciliation candidates.', 0, $e);
        }
    }

    private function normalizeDate(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        if ($endOfDay) {
            $date = $date->setTime(23, 59, 59);
        }
        return $date->format('Y-m-d H:i:s');
    }
}
