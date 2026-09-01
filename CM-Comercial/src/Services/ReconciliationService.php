<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepositoryInterface;
use App\Repositories\PaymentTransactionRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Dedicated domain service for financial reconciliation.
 *
 * All persistence is delegated to repositories. PDO is used exclusively for
 * an ACID transaction boundary; no SQL is present in this service.
 */
final class ReconciliationService
{
    private const MAX_PAGE_SIZE = 100;
    private const DEFAULT_PAGE_SIZE = 50;
    private const AMOUNT_TOLERANCE = 0.01;

    /** @var array<string, true> */
    private const PAYMENT_STATUSES = [
        'pending' => true,
        'authorized' => true,
        'paid' => true,
        'failed' => true,
        'cancelled' => true,
        'refunded' => true,
    ];

    /** @var array<string, array<string, mixed>> */
    private array $idempotencyCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly PaymentTransactionRepositoryInterface $paymentRepository,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * Return an idempotent snapshot for the current process.
     *
     * Reconciliation is read-only, so repeated execution has no financial side
     * effect. The idempotency key additionally prevents duplicate repository
     * work during the lifetime of this service instance.
     *
     * @param array<string, mixed> $filters
     * @return array{summary:array<string,int|float>,page:array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,page:int,total_pages:int}}
     */
    public function reconcile(
        string $idempotencyKey,
        array $filters = [],
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0
    ): array {
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Invalid reconciliation idempotency key.');
        }

        $cacheKey = hash('sha256', $key);
        if (isset($this->idempotencyCache[$cacheKey])) {
            /** @var array{summary:array<string,int|float>,page:array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,page:int,total_pages:int}} $cached */
            $cached = $this->idempotencyCache[$cacheKey];
            return $cached;
        }

        $normalized = $this->normalizeFilters($filters);
        $snapshot = $this->transaction(
            fn (): array => [
                'summary' => $this->buildSummary($normalized),
                'page' => $this->buildPage($normalized, $limit, $offset),
            ]
        );

        $this->idempotencyCache[$cacheKey] = $snapshot;
        return $snapshot;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,page:int,total_pages:int}
     */
    public function getPage(
        array $filters = [],
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0
    ): array {
        $normalized = $this->normalizeFilters($filters);

        return $this->transaction(
            fn (): array => $this->buildPage($normalized, $limit, $offset)
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int|float>
     */
    public function getSummary(array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);

        return $this->transaction(
            fn (): array => $this->buildSummary($normalized)
        );
    }

    /**
     * @template T
     * @param callable(PDO):T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException(
                'Reconciliation transaction cannot start inside another transaction.'
            );
        }

        $this->db->beginTransaction();

        try {
            $result = $operation($this->db);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $payment */
    public function classifyPayment(array $payment): array
    {
        $paymentId = (int)($payment['id'] ?? 0);
        $orderId = (int)($payment['order_id'] ?? 0);

        if ($paymentId < 1) {
            return $this->withClassification(
                $payment,
                'inconsistent',
                'invalid_transaction_identity'
            );
        }

        if (
            $orderId < 1
            || !array_key_exists('order_status', $payment)
            || $payment['order_status'] === null
        ) {
            return $this->withClassification(
                $payment,
                'inconsistent',
                'orphan_transaction'
            );
        }

        $paymentAmount = round((float)($payment['amount'] ?? 0), 2);
        $orderAmount = round(
            (float)($payment['order_total'] ?? $payment['total'] ?? 0),
            2
        );

        if (abs($paymentAmount - $orderAmount) > self::AMOUNT_TOLERANCE) {
            return $this->withClassification(
                $payment,
                'divergent',
                'amount_mismatch'
            );
        }

        $paymentStatus = strtolower(trim((string)($payment['status'] ?? '')));
        $orderStatus = strtolower(trim((string)($payment['order_status'] ?? '')));
        $orderPaymentStatus = strtolower(
            trim((string)($payment['order_payment_status'] ?? ''))
        );

        if (!isset(self::PAYMENT_STATUSES[$paymentStatus])) {
            return $this->withClassification(
                $payment,
                'inconsistent',
                'unknown_payment_status'
            );
        }

        if ($paymentStatus === 'paid' && $orderStatus === 'cancelled') {
            return $this->withClassification(
                $payment,
                'divergent',
                'status_mismatch'
            );
        }

        if ($orderPaymentStatus !== '' && $orderPaymentStatus !== $paymentStatus) {
            $accepted = match ($paymentStatus) {
                'authorized' => ['authorized', 'pending'],
                'cancelled' => ['cancelled', 'failed'],
                'refunded' => ['refunded'],
                default => [$paymentStatus],
            };

            if (!in_array($orderPaymentStatus, $accepted, true)) {
                return $this->withClassification(
                    $payment,
                    'divergent',
                    'status_mismatch'
                );
            }
        }

        if (in_array($paymentStatus, ['pending', 'authorized'], true)) {
            return $this->withClassification($payment, 'pending', '');
        }

        return $this->withClassification($payment, 'reconciled', '');
    }

    /** @param array<string,mixed> $filters */
    private function buildPage(array $filters, int $limit, int $offset): array
    {
        [$limit, $offset] = $this->normalizePagination($limit, $offset);

        $paymentSummary = $this->paymentRepository->summarize($filters);
        $paymentTotal = (int)($paymentSummary['count'] ?? 0);

        $orderFilters = $this->orderFilters($filters);
        $missingTotal = $this->hasTransactionOnlyFilter($filters)
            ? 0
            : $this->orderRepository->countWithoutPaymentTransaction($orderFilters);

        $total = $paymentTotal + $missingTotal;
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = $total === 0
            ? 1
            : min((int)floor($offset / $limit) + 1, $totalPages);
        $effectiveOffset = ($page - 1) * $limit;
        $items = [];

        if ($effectiveOffset < $paymentTotal) {
            foreach (
                $this->paymentRepository->listWithFilters(
                    $filters,
                    $limit,
                    $effectiveOffset
                ) as $payment
            ) {
                $items[] = $this->classifyPayment($payment);
            }

            $remaining = $limit - count($items);
            if (
                $remaining > 0
                && $effectiveOffset + count($items) >= $paymentTotal
                && $missingTotal > 0
            ) {
                foreach (
                    $this->orderRepository->listWithoutPaymentTransaction(
                        $orderFilters,
                        $remaining,
                        0
                    ) as $order
                ) {
                    $items[] = $this->classifyMissingOrder($order);
                }
            }
        } elseif ($missingTotal > 0) {
            $missingOffset = max(0, $effectiveOffset - $paymentTotal);
            foreach (
                $this->orderRepository->listWithoutPaymentTransaction(
                    $orderFilters,
                    $limit,
                    $missingOffset
                ) as $order
            ) {
                $items[] = $this->classifyMissingOrder($order);
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $effectiveOffset,
            'page' => $page,
            'total_pages' => $totalPages,
        ];
    }

    /** @param array<string,mixed> $filters */
    private function buildSummary(array $filters): array
    {
        $paymentSummary = $this->paymentRepository->summarizeForReconciliation(
            $filters
        );

        $orderFilters = $this->orderFilters($filters);
        $missingTotal = $this->hasTransactionOnlyFilter($filters)
            ? 0
            : $this->orderRepository->countWithoutPaymentTransaction($orderFilters);

        $paymentTotal = (int)(
            $paymentSummary['total']
            ?? $paymentSummary['count']
            ?? 0
        );

        return [
            'total' => $paymentTotal + $missingTotal,
            'payment_transactions' => $paymentTotal,
            'reconciled' => (int)($paymentSummary['reconciled'] ?? 0),
            'divergent' => (int)($paymentSummary['divergent'] ?? 0),
            'pending' => (int)($paymentSummary['pending'] ?? 0),
            'inconsistent' => (int)($paymentSummary['inconsistent'] ?? 0) + $missingTotal,
            'orphan_transactions' => (int)($paymentSummary['orphan_transactions'] ?? $paymentSummary['orphan_count'] ?? 0),
            'missing_transactions' => $missingTotal,
            'amount_mismatches' => (int)($paymentSummary['amount_mismatches'] ?? $paymentSummary['amount_mismatch_count'] ?? 0),
            'status_mismatches' => (int)($paymentSummary['status_mismatches'] ?? $paymentSummary['status_mismatch_count'] ?? 0),
            'total_amount' => round((float)($paymentSummary['total_amount'] ?? $paymentSummary['total'] ?? 0), 2),
            'paid_amount' => round((float)($paymentSummary['paid'] ?? 0), 2),
            'refunded_amount' => round((float)($paymentSummary['refunded'] ?? 0), 2),
            'pending_amount' => round((float)($paymentSummary['pending'] ?? 0), 2),
            'failed_amount' => round((float)($paymentSummary['failed'] ?? 0), 2),
            'cancelled_amount' => round((float)($paymentSummary['cancelled'] ?? 0), 2),
            'authorized_amount' => round((float)($paymentSummary['authorized'] ?? 0), 2),
        ];
    }

    /** @param array<string,mixed> $order */
    private function classifyMissingOrder(array $order): array
    {
        return $this->withClassification(
            [
                'id' => null,
                'order_id' => $order['id'] ?? null,
                'customer_name' => $order['customer_name'] ?? $order['user_name'] ?? null,
                'order_email' => $order['user_email'] ?? $order['email'] ?? null,
                'amount' => $order['total'] ?? $order['total_amount'] ?? 0,
                'provider' => null,
                'method' => null,
                'status' => null,
                'created_at' => $order['created_at'] ?? null,
                'updated_at' => null,
                'order_status' => $order['status'] ?? null,
                'order_payment_status' => $order['payment_status'] ?? null,
            ],
            'inconsistent',
            'missing_payment_transaction'
        );
    }

    /** @param array<string,mixed> $row */
    private function withClassification(array $row, string $status, string $reason): array
    {
        $row['reconciliation_status'] = $status;
        $row['divergence_reason'] = $reason;
        return $row;
    }

    /** @param array<string,mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        if (isset($filters['status'])) {
            if (!is_string($filters['status'])) {
                throw new InvalidArgumentException('Invalid reconciliation payment status filter.');
            }
            $status = trim($filters['status']);
            if ($status !== '') {
                if (!isset(self::PAYMENT_STATUSES[$status])) {
                    throw new InvalidArgumentException('Invalid reconciliation payment status filter.');
                }
                $normalized['status'] = $status;
            }
        }

        if (isset($filters['provider'])) {
            if (!is_string($filters['provider'])) {
                throw new InvalidArgumentException('Invalid provider filter.');
            }
            $provider = trim($filters['provider']);
            if ($provider !== '') {
                $normalized['provider'] = substr($provider, 0, 40);
            }
        }

        foreach (['customer_id', 'order_id'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) {
                continue;
            }
            $value = $filters[$key];
            if (!is_int($value) && !ctype_digit((string)$value)) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = (int)$value;
            if ($value < 1) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $normalized[$key] = $value;
        }

        foreach (['date_from', 'date_to', 'search'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null) {
                continue;
            }
            if (!is_string($filters[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = trim($filters[$key]);
            if ($value !== '') {
                $normalized[$key] = $key === 'search'
                    ? substr($value, 0, 120)
                    : $value;
            }
        }

        foreach (['date_from', 'date_to'] as $key) {
            if (isset($normalized[$key]) && !$this->isValidDate((string)$normalized[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter. Expected Y-m-d.");
            }
        }

        if (
            isset($normalized['date_from'], $normalized['date_to'])
            && (string)$normalized['date_from'] > (string)$normalized['date_to']
        ) {
            throw new InvalidArgumentException('date_from cannot be greater than date_to.');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $filters */
    private function orderFilters(array $filters): array
    {
        $result = [];
        foreach (['customer_id', 'order_id', 'date_from', 'date_to', 'search'] as $key) {
            if (array_key_exists($key, $filters)) {
                $result[$key] = $filters[$key];
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $filters */
    private function hasTransactionOnlyFilter(array $filters): bool
    {
        return isset($filters['provider']) || isset($filters['status']);
    }

    /** @return array{0:int,1:int} */
    private function normalizePagination(int $limit, int $offset): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Pagination limit must be greater than zero.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Pagination offset cannot be negative.');
        }
        return [min(self::MAX_PAGE_SIZE, $limit), $offset];
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
