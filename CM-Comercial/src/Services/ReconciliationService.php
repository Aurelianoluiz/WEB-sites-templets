<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepositoryInterface;
use App\Repositories\PaymentTransactionRepositoryInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Domain service dedicated to payment/order reconciliation.
 *
 * No SQL is executed here. Persistence is delegated to repositories.
 * The service classifies persisted payment/order data and composes a stable,
 * deterministic reconciliation read model.
 */
final class ReconciliationService
{
    private const MAX_PAGE_SIZE = 100;
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

    public function __construct(
        private readonly PDO $db,
        private readonly PaymentTransactionRepositoryInterface $paymentRepository,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,page:int,total_pages:int}
     */
    public function getPage(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        $filters = $this->normalizeFilters($filters);

        try {
            $paymentSummary = $this->paymentRepository->summarize($filters);
            $paymentTotal = (int)($paymentSummary['count'] ?? 0);
            $missingOrderFilters = $this->orderFilters($filters);
            $missingOrderTotal = $this->hasTransactionOnlyFilter($filters)
                ? 0
                : $this->orderRepository->countWithoutPaymentTransaction($missingOrderFilters);

            $total = $paymentTotal + $missingOrderTotal;
            $totalPages = max(1, (int)ceil($total / $limit));
            $page = $total === 0 ? 1 : min((int)floor($offset / $limit) + 1, $totalPages);
            $effectiveOffset = ($page - 1) * $limit;
            $items = [];

            if ($effectiveOffset < $paymentTotal) {
                $payments = $this->paymentRepository->listWithFilters($filters, $limit, $effectiveOffset);
                foreach ($payments as $payment) {
                    $items[] = $this->classifyPayment($payment);
                }

                $remaining = $limit - count($items);
                if ($remaining > 0 && $effectiveOffset + count($payments) >= $paymentTotal && $missingOrderTotal > 0) {
                    foreach ($this->orderRepository->listWithoutPaymentTransaction($missingOrderFilters, $remaining, 0) as $order) {
                        $items[] = $this->classifyMissingOrder($order);
                    }
                }
            } else {
                $missingOffset = max(0, $effectiveOffset - $paymentTotal);
                foreach ($this->orderRepository->listWithoutPaymentTransaction($missingOrderFilters, $limit, $missingOffset) as $order) {
                    $items[] = $this->classifyMissingOrder($order);
                }
            }

            return [
                'items' => $this->deduplicateItems($items),
                'total' => $total,
                'limit' => $limit,
                'offset' => $effectiveOffset,
                'page' => $page,
                'total_pages' => $totalPages,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to build reconciliation result.', 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *  total:int,
     *  reconciled:int,
     *  divergent:int,
     *  pending:int,
     *  inconsistent:int,
     *  orphan_transactions:int,
     *  missing_transactions:int,
     *  amount_mismatches:int,
     *  status_mismatches:int,
     *  total_amount:float,
     *  paid_amount:float,
     *  refunded_amount:float,
     *  pending_amount:float,
     *  failed_amount:float,
     *  cancelled_amount:float,
     *  authorized_amount:float
     * }
     */
    public function getSummary(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        try {
            $paymentSummary = $this->paymentRepository->summarize($filters);
            $payments = $this->loadAllPaymentsForAnalysis($filters);
            $missingFilters = $this->orderFilters($filters);
            $missingOrders = $this->loadAllMissingOrdersForAnalysis(
                $missingFilters,
                $this->hasTransactionOnlyFilter($filters)
            );

            $summary = [
                'total' => 0,
                'reconciled' => 0,
                'divergent' => 0,
                'pending' => 0,
                'inconsistent' => 0,
                'orphan_transactions' => 0,
                'missing_transactions' => 0,
                'amount_mismatches' => 0,
                'status_mismatches' => 0,
                'total_amount' => round((float)($paymentSummary['total'] ?? 0), 2),
                'paid_amount' => round((float)($paymentSummary['paid'] ?? 0), 2),
                'refunded_amount' => round((float)($paymentSummary['refunded'] ?? 0), 2),
                'pending_amount' => round((float)($paymentSummary['pending'] ?? 0), 2),
                'failed_amount' => round((float)($paymentSummary['failed'] ?? 0), 2),
                'cancelled_amount' => round((float)($paymentSummary['cancelled'] ?? 0), 2),
                'authorized_amount' => round((float)($paymentSummary['authorized'] ?? 0), 2),
            ];

            foreach ($payments as $payment) {
                $classified = $this->classifyPayment($payment);
                $state = (string)$classified['reconciliation_status'];
                $summary['total']++;
                $summary[$state]++;

                $reason = (string)$classified['divergence_reason'];
                $summary['orphan_transactions'] += $reason === 'orphan_transaction' ? 1 : 0;
                $summary['amount_mismatches'] += $reason === 'amount_mismatch' ? 1 : 0;
                $summary['status_mismatches'] += $reason === 'status_mismatch' ? 1 : 0;
            }

            foreach ($missingOrders as $order) {
                $this->classifyMissingOrder($order);
                $summary['total']++;
                $summary['inconsistent']++;
                $summary['missing_transactions']++;
            }

            if ((int)($paymentSummary['count'] ?? 0) !== count($payments)) {
                throw new RuntimeException('Reconciliation source changed during analysis; retry is required.');
            }

            return $summary;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to calculate reconciliation summary.', 0, $e);
        }
    }

    /**
     * Runs a repository-backed reconciliation operation inside an ACID boundary.
     * PDO is used only for the transaction lifecycle; SQL remains repository-owned.
     *
     * @template T
     * @param callable(PDO):T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Reconciliation transaction cannot start inside another transaction.');
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
            return $this->withClassification($payment, 'inconsistent', 'invalid_transaction_identity');
        }
        if ($orderId < 1 || !array_key_exists('order_status', $payment) || $payment['order_status'] === null) {
            return $this->withClassification($payment, 'inconsistent', 'orphan_transaction');
        }

        $paymentAmount = round((float)($payment['amount'] ?? 0), 2);
        $orderAmount = round((float)($payment['order_total'] ?? 0), 2);
        if (abs($paymentAmount - $orderAmount) > self::AMOUNT_TOLERANCE) {
            return $this->withClassification($payment, 'divergent', 'amount_mismatch');
        }

        $paymentStatus = strtolower(trim((string)($payment['status'] ?? '')));
        $orderStatus = strtolower(trim((string)($payment['order_status'] ?? '')));
        $orderPaymentStatus = strtolower(trim((string)($payment['order_payment_status'] ?? '')));

        if (!isset(self::PAYMENT_STATUSES[$paymentStatus])) {
            return $this->withClassification($payment, 'inconsistent', 'unknown_payment_status');
        }
        if ($paymentStatus === 'paid' && $orderStatus === 'cancelled') {
            return $this->withClassification($payment, 'divergent', 'status_mismatch');
        }
        if ($orderPaymentStatus !== '' && $orderPaymentStatus !== $paymentStatus) {
            $accepted = match ($paymentStatus) {
                'authorized' => ['authorized', 'pending'],
                'cancelled' => ['cancelled', 'failed'],
                default => [$paymentStatus],
            };
            if (!in_array($orderPaymentStatus, $accepted, true)) {
                return $this->withClassification($payment, 'divergent', 'status_mismatch');
            }
        }
        if (in_array($paymentStatus, ['pending', 'authorized'], true)) {
            return $this->withClassification($payment, 'pending', '');
        }
        return $this->withClassification($payment, 'reconciled', '');
    }

    /** @param array<string,mixed> $order */
    private function classifyMissingOrder(array $order): array
    {
        return $this->withClassification([
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
        ], 'inconsistent', 'missing_payment_transaction');
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
        if (isset($filters['status']) && is_string($filters['status'])) {
            $value = trim($filters['status']);
            if ($value !== '') {
                if (!isset(self::PAYMENT_STATUSES[$value])) {
                    throw new InvalidArgumentException('Invalid reconciliation status filter.');
                }
                $normalized['status'] = $value;
            }
        }
        if (isset($filters['provider']) && is_string($filters['provider'])) {
            $value = trim($filters['provider']);
            if ($value !== '') {
                $normalized['provider'] = substr($value, 0, 40);
            }
        }
        foreach (['customer_id', 'order_id'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === '' || $filters[$key] === null) {
                continue;
            }
            if (!is_int($filters[$key]) && !ctype_digit((string)$filters[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = (int)$filters[$key];
            if ($value < 1) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $normalized[$key] = $value;
        }
        foreach (['date_from', 'date_to', 'search'] as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            if (!is_string($filters[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = trim($filters[$key]);
            if ($value !== '') {
                $normalized[$key] = $key === 'search' ? substr($value, 0, 120) : $value;
            }
        }
        foreach (['date_from', 'date_to'] as $key) {
            if (isset($normalized[$key]) && !$this->isValidDate((string)$normalized[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter. Expected Y-m-d.");
            }
        }
        if (isset($normalized['date_from'], $normalized['date_to']) && $normalized['date_from'] > $normalized['date_to']) {
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

    /** @param array<string,mixed> $filters */
    private function loadAllPaymentsForAnalysis(array $filters): array
    {
        $all = [];
        $offset = 0;
        do {
            $page = $this->paymentRepository->listWithFilters($filters, self::MAX_PAGE_SIZE, $offset);
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $all[] = $row;
            }
            $offset += count($page);
        } while (count($page) === self::MAX_PAGE_SIZE);
        return $all;
    }

    /** @param array<string,mixed> $filters */
    private function loadAllMissingOrdersForAnalysis(array $filters, bool $skip): array
    {
        if ($skip) {
            return [];
        }
        $all = [];
        $offset = 0;
        do {
            $page = $this->orderRepository->listWithoutPaymentTransaction($filters, self::MAX_PAGE_SIZE, $offset);
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $all[] = $row;
            }
            $offset += count($page);
        } while (count($page) === self::MAX_PAGE_SIZE);
        return $all;
    }

    /** @param list<array<string,mixed>> $items */
    private function deduplicateItems(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $identity = ((string)($item['id'] ?? 'payment')) . ':' . ((string)($item['order_id'] ?? 'order'));
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $result[] = $item;
        }
        return $result;
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
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
