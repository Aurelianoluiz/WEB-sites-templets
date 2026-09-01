<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;
use Throwable;

final class ReconciliationRepository implements ReconciliationRepositoryInterface
{
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Returns a canonical reconciliation read model composed from payment_transactions
     * and orders. Sensitive gateway payload columns are intentionally excluded.
     *
     * @param array<string, scalar|null> $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        [$where, $params] = $this->buildFilters($filters, 'r');
        $sql = $this->baseCte()
            . ' SELECT r.* FROM reconciliation_rows r'
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' ORDER BY r.sort_timestamp DESC, r.sort_id DESC LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $this->sanitizeRows($rows) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns aggregate counts and monetary totals for the reconciliation dataset.
     *
     * @param array<string, scalar|null> $filters
     * @return array<string, int|float>
     */
    public function summarize(array $filters = []): array
    {
        [$where, $params] = $this->buildFilters($filters, 'r');
        $sql = $this->baseCte()
            . ' SELECT '
            . 'COUNT(*) AS total_count, '
            . 'COALESCE(SUM(r.amount), 0) AS total_amount, '
            . 'SUM(CASE WHEN r.reconciliation_status = \'reconciled\' THEN 1 ELSE 0 END) AS reconciled_count, '
            . 'SUM(CASE WHEN r.reconciliation_status = \'divergent\' THEN 1 ELSE 0 END) AS divergent_count, '
            . 'SUM(CASE WHEN r.reconciliation_status = \'pending\' THEN 1 ELSE 0 END) AS pending_count, '
            . 'SUM(CASE WHEN r.reconciliation_status = \'inconsistent\' THEN 1 ELSE 0 END) AS inconsistent_count, '
            . 'SUM(CASE WHEN r.divergence_reason = \'amount_mismatch\' THEN 1 ELSE 0 END) AS amount_mismatch_count, '
            . 'SUM(CASE WHEN r.divergence_reason = \'status_mismatch\' THEN 1 ELSE 0 END) AS status_mismatch_count, '
            . 'SUM(CASE WHEN r.divergence_reason = \'orphan_transaction\' THEN 1 ELSE 0 END) AS orphan_count, '
            . 'SUM(CASE WHEN r.divergence_reason = \'missing_payment_transaction\' THEN 1 ELSE 0 END) AS missing_transaction_count '
            . 'FROM reconciliation_rows r'
            . ($where !== '' ? ' WHERE ' . $where : '');

        try {
            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return $this->emptySummary();
            }

            return [
                'total' => (int)($row['total_count'] ?? 0),
                'count' => (int)($row['total_count'] ?? 0),
                'total_amount' => round((float)($row['total_amount'] ?? 0), 2),
                'reconciled' => (int)($row['reconciled_count'] ?? 0),
                'divergent' => (int)($row['divergent_count'] ?? 0),
                'pending' => (int)($row['pending_count'] ?? 0),
                'inconsistent' => (int)($row['inconsistent_count'] ?? 0),
                'amount_mismatches' => (int)($row['amount_mismatch_count'] ?? 0),
                'status_mismatches' => (int)($row['status_mismatch_count'] ?? 0),
                'orphan_transactions' => (int)($row['orphan_count'] ?? 0),
                'missing_transactions' => (int)($row['missing_transaction_count'] ?? 0),
                'paid_amount' => 0.0,
                'refunded_amount' => 0.0,
                'pending_amount' => 0.0,
                'failed_amount' => 0.0,
                'cancelled_amount' => 0.0,
                'authorized_amount' => 0.0,
            ];
        } catch (Throwable) {
            return $this->emptySummary();
        }
    }

    /**
     * Counts reconciliation rows after all filters are applied.
     *
     * @param array<string, scalar|null> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters, 'r');
        $sql = $this->baseCte()
            . ' SELECT COUNT(*) FROM reconciliation_rows r'
            . ($where !== '' ? ' WHERE ' . $where : '');

        try {
            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->execute();
            return max(0, (int)$stmt->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Canonical source query. It intentionally selects no gateway payload,
     * token, credential, or signature material.
     */
    private function baseCte(): string
    {
        return <<<'SQL'
WITH reconciliation_rows AS (
    SELECT
        pt.id AS transaction_id,
        pt.order_id,
        o.customer_id,
        pt.provider,
        pt.provider_payment_id,
        pt.external_reference,
        pt.status AS payment_status,
        pt.amount,
        pt.currency,
        o.status AS order_status,
        o.payment_status AS order_payment_status,
        o.total_amount AS order_total_amount,
        CASE
            WHEN o.id IS NULL THEN 'inconsistent'
            WHEN ROUND(pt.amount, 2) <> ROUND(o.total_amount, 2) THEN 'divergent'
            WHEN pt.status NOT IN ('pending','authorized','paid','failed','cancelled','refunded') THEN 'inconsistent'
            WHEN pt.status = 'paid' AND o.status = 'cancelled' THEN 'divergent'
            WHEN pt.status = 'paid' AND o.payment_status <> 'paid' THEN 'divergent'
            WHEN pt.status = 'refunded' AND o.payment_status <> 'refunded' THEN 'divergent'
            WHEN pt.status IN ('pending','authorized') THEN 'pending'
            ELSE 'reconciled'
        END AS reconciliation_status,
        CASE
            WHEN o.id IS NULL THEN 'orphan_transaction'
            WHEN ROUND(pt.amount, 2) <> ROUND(o.total_amount, 2) THEN 'amount_mismatch'
            WHEN pt.status NOT IN ('pending','authorized','paid','failed','cancelled','refunded') THEN 'unknown_payment_status'
            WHEN pt.status = 'paid' AND o.status = 'cancelled' THEN 'status_mismatch'
            WHEN pt.status = 'paid' AND o.payment_status <> 'paid' THEN 'status_mismatch'
            WHEN pt.status = 'refunded' AND o.payment_status <> 'refunded' THEN 'status_mismatch'
            ELSE NULL
        END AS divergence_reason,
        pt.created_at,
        pt.updated_at,
        pt.created_at AS sort_timestamp,
        pt.id AS sort_id,
        'payment_transaction' AS record_type
    FROM payment_transactions pt
    LEFT JOIN orders o ON o.id = pt.order_id

    UNION ALL

    SELECT
        NULL AS transaction_id,
        o.id AS order_id,
        o.customer_id,
        NULL AS provider,
        NULL AS provider_payment_id,
        NULL AS external_reference,
        NULL AS payment_status,
        o.total_amount AS amount,
        o.currency,
        o.status AS order_status,
        o.payment_status AS order_payment_status,
        o.total_amount AS order_total_amount,
        'inconsistent' AS reconciliation_status,
        'missing_payment_transaction' AS divergence_reason,
        o.created_at,
        o.updated_at,
        o.created_at AS sort_timestamp,
        o.id AS sort_id,
        'order_without_transaction' AS record_type
    FROM orders o
    LEFT JOIN payment_transactions pt ON pt.order_id = o.id
    WHERE pt.id IS NULL
)
SQL;
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array{0:string,1:array<string, scalar|null>}
     */
    private function buildFilters(array $filters, string $alias): array
    {
        $conditions = [];
        $params = [];

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $conditions[] = "{$alias}.payment_status = :status";
            $params[':status'] = $status;
        }

        $provider = $filters['provider'] ?? null;
        if (is_string($provider) && trim($provider) !== '') {
            $conditions[] = "{$alias}.provider = :provider";
            $params[':provider'] = trim($provider);
        }

        $customerId = $filters['customer_id'] ?? null;
        if (is_int($customerId) || (is_string($customerId) && ctype_digit($customerId))) {
            $conditions[] = "{$alias}.customer_id = :customer_id";
            $params[':customer_id'] = (int)$customerId;
        }

        $orderId = $filters['order_id'] ?? null;
        if (is_int($orderId) || (is_string($orderId) && ctype_digit($orderId))) {
            $conditions[] = "{$alias}.order_id = :order_id";
            $params[':order_id'] = (int)$orderId;
        }

        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $conditions[] = "{$alias}.created_at >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $conditions[] = "{$alias}.created_at <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59.999999';
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $conditions[] = "(CAST({$alias}.transaction_id AS CHAR) LIKE :search_tx
                OR CAST({$alias}.order_id AS CHAR) LIKE :search_order
                OR {$alias}.provider_payment_id LIKE :search_provider_id
                OR {$alias}.external_reference LIKE :search_reference
                OR CAST({$alias}.customer_id AS CHAR) LIKE :search_customer)";
            $params[':search_tx'] = $term;
            $params[':search_order'] = $term;
            $params[':search_provider_id'] = $term;
            $params[':search_reference'] = $term;
            $params[':search_customer'] = $term;
        }

        $reconciliationStatus = $filters['reconciliation_status'] ?? null;
        if (is_string($reconciliationStatus) && $reconciliationStatus !== '') {
            $conditions[] = "{$alias}.reconciliation_status = :reconciliation_status";
            $params[':reconciliation_status'] = $reconciliationStatus;
        }

        $reason = $filters['divergence_reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            $conditions[] = "{$alias}.divergence_reason = :divergence_reason";
            $params[':divergence_reason'] = $reason;
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue(
                $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }

    /** @return array{0:int,1:int} */
    private function normalizePagination(int $limit, int $offset): array
    {
        if ($limit < 1) {
            $limit = 1;
        }
        if ($offset < 0) {
            $offset = 0;
        }
        return [min(self::MAX_PAGE_SIZE, $limit), $offset];
    }

    /** @param list<array<string,mixed>> $rows */
    private function sanitizeRows(array $rows): array
    {
        $allowed = [
            'transaction_id', 'order_id', 'customer_id', 'provider',
            'provider_payment_id', 'external_reference', 'payment_status',
            'amount', 'currency', 'order_status', 'order_payment_status',
            'order_total_amount', 'reconciliation_status', 'divergence_reason',
            'created_at', 'updated_at', 'record_type',
        ];

        return array_map(
            static fn(array $row): array => array_intersect_key($row, array_flip($allowed)),
            $rows
        );
    }

    /** @return array<string,int|float> */
    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'count' => 0,
            'total_amount' => 0.0,
            'reconciled' => 0,
            'divergent' => 0,
            'pending' => 0,
            'inconsistent' => 0,
            'amount_mismatches' => 0,
            'status_mismatches' => 0,
            'orphan_transactions' => 0,
            'missing_transactions' => 0,
            'paid_amount' => 0.0,
            'refunded_amount' => 0.0,
            'pending_amount' => 0.0,
            'failed_amount' => 0.0,
            'cancelled_amount' => 0.0,
            'authorized_amount' => 0.0,
        ];
    }
}
