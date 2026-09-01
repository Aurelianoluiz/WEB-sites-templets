<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentAuditRepositoryInterface;
use App\Repositories\PaymentTransactionRepositoryInterface;
use App\Repositories\ReconciliationRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReconciliationService
{
    private const MAX_PAGE_SIZE = 100;
    private const DEFAULT_PAGE_SIZE = 50;
    private const AMOUNT_TOLERANCE = 0.01;

    /** @var array<string,true> */
    private const PAYMENT_STATUSES = [
        'pending' => true,
        'authorized' => true,
        'paid' => true,
        'failed' => true,
        'cancelled' => true,
        'refunded' => true,
    ];

    /** @var array<string,true> */
    private const RESOLVABLE_STATUSES = [
        'pending' => true,
        'authorized' => true,
        'paid' => true,
        'failed' => true,
        'cancelled' => true,
        'refunded' => true,
    ];

    public function __construct(
        private readonly ReconciliationRepositoryInterface $reconciliationRepository,
        private readonly PaymentTransactionRepositoryInterface $paymentRepository,
        private readonly PaymentAuditRepositoryInterface $auditRepository
    ) {
    }

    /** @param array<string,mixed> $filters */
    public function reconcile(
        string $idempotencyKey,
        array $filters = [],
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0
    ): array {
        $key = $this->normalizeIdempotencyKey($idempotencyKey);
        $filters = $this->normalizeFilters($filters);
        [$limit, $offset] = $this->normalizePagination($limit, $offset);

        try {
            return $this->auditRepository->transaction(function () use ($key, $filters, $limit, $offset): array {
                $summary = $this->reconciliationRepository->summarize($filters);
                $page = $this->buildPage($filters, $limit, $offset);
                $snapshot = ['summary' => $summary, 'page' => $page];
                return $this->sanitizedSnapshot($snapshot);
            });
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to build reconciliation snapshot.', 0, $e);
        }
    }

    /** @param array<string,mixed> $filters */
    public function getPage(
        array $filters = [],
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0
    ): array {
        $filters = $this->normalizeFilters($filters);
        [$limit, $offset] = $this->normalizePagination($limit, $offset);

        try {
            return $this->sanitizedPage($this->buildPage($filters, $limit, $offset));
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to load reconciliation page.', 0, $e);
        }
    }

    /** @param array<string,mixed> $filters */
    public function getSummary(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        try {
            return $this->sanitizedSummary($this->reconciliationRepository->summarize($filters));
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to calculate reconciliation summary.', 0, $e);
        }
    }

    /**
     * Resolve a persisted reconciliation divergence and record the decision
     * atomically. No SQL or PDO calls are made here.
     *
     * @return array{success:bool,transaction_id:int,old_status:string,new_status:string,idempotent:bool,message:string}
     */
    public function resolveDivergence(
        int $transactionId,
        string $actor,
        string $newStatus,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        if ($transactionId < 1) {
            throw new InvalidArgumentException('transactionId must be a positive integer.');
        }

        $actor = $this->normalizeActor($actor);
        $newStatus = strtolower(trim($newStatus));
        if (!isset(self::RESOLVABLE_STATUSES[$newStatus])) {
            throw new InvalidArgumentException('Invalid payment status for reconciliation resolution.');
        }
        $reason = $this->normalizeReason($reason);
        $key = $idempotencyKey === null ? null : $this->normalizeIdempotencyKey($idempotencyKey);

        try {
            return $this->auditRepository->transaction(function () use ($transactionId, $actor, $newStatus, $reason, $key): array {
                if ($key !== null && $this->auditRepository->isEventProcessed($key)) {
                    return [
                        'success' => true,
                        'transaction_id' => $transactionId,
                        'old_status' => $newStatus,
                        'new_status' => $newStatus,
                        'idempotent' => true,
                        'message' => 'Reconciliation resolution already processed.',
                    ];
                }

                $payment = $this->paymentRepository->findById($transactionId, true);
                if ($payment === null) {
                    throw new RuntimeException('Payment transaction not found.');
                }

                $classified = $this->classifyPayment($payment);
                if ($classified['reconciliation_status'] !== 'divergent') {
                    throw new RuntimeException('Only divergent payment transactions can be resolved.');
                }

                $oldStatus = strtolower(trim((string)($payment['status'] ?? '')));
                if (!isset(self::PAYMENT_STATUSES[$oldStatus])) {
                    throw new RuntimeException('Current payment status is invalid.');
                }

                if ($oldStatus === $newStatus) {
                    throw new RuntimeException('Resolution must change the payment status.');
                }

                if (!$this->paymentRepository->updateStatus($transactionId, $newStatus)) {
                    throw new RuntimeException('Payment status update did not affect a transaction.');
                }

                if (!$this->auditRepository->logResolution(
                    $transactionId,
                    $actor,
                    $oldStatus,
                    $newStatus,
                    $reason,
                    $key
                )) {
                    throw new RuntimeException('Payment audit resolution could not be recorded.');
                }

                return [
                    'success' => true,
                    'transaction_id' => $transactionId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'idempotent' => false,
                    'message' => 'Reconciliation divergence resolved successfully.',
                ];
            });
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Unable to resolve reconciliation divergence.', 0, $e);
        }
    }

    /** @param array<string,mixed> $payment */
    public function classifyPayment(array $payment): array
    {
        $transactionId = (int)($payment['id'] ?? $payment['transaction_id'] ?? 0);
        $orderId = (int)($payment['order_id'] ?? 0);
        if ($transactionId < 1) {
            return $this->withClassification($payment, 'inconsistent', 'invalid_transaction_identity');
        }
        if ($orderId < 1 || !array_key_exists('order_status', $payment) || $payment['order_status'] === null) {
            return $this->withClassification($payment, 'inconsistent', 'orphan_transaction');
        }

        $paymentAmount = round((float)($payment['amount'] ?? 0), 2);
        $orderAmount = round((float)($payment['order_total'] ?? $payment['order_total_amount'] ?? 0), 2);
        if (abs($paymentAmount - $orderAmount) > self::AMOUNT_TOLERANCE) {
            return $this->withClassification($payment, 'divergent', 'amount_mismatch');
        }

        $paymentStatus = strtolower(trim((string)($payment['status'] ?? $payment['payment_status'] ?? '')));
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

    /** @param array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,page:int,total_pages:int} $page */
    private function sanitizedPage(array $page): array
    {
        $page['items'] = array_map(function (array $row): array {
            foreach (['gateway_payload', 'raw_payload', 'webhook_payload', 'pix_qr_code', 'pix_qr_code_base64', 'access_token', 'token', 'secret', 'credentials'] as $sensitive) {
                unset($row[$sensitive]);
            }
            return $row;
        }, $page['items']);
        return $page;
    }

    /** @param array<string,mixed> $snapshot */
    private function sanitizedSnapshot(array $snapshot): array
    {
        $snapshot['page'] = $this->sanitizedPage($snapshot['page']);
        $snapshot['summary'] = $this->sanitizedSummary($snapshot['summary']);
        return $snapshot;
    }

    /** @param array<string,mixed> $summary */
    private function sanitizedSummary(array $summary): array
    {
        return array_intersect_key($summary, array_flip([
            'total', 'count', 'total_amount', 'reconciled', 'divergent', 'pending',
            'inconsistent', 'amount_mismatches', 'status_mismatches',
            'orphan_transactions', 'missing_transactions', 'paid_amount',
            'refunded_amount', 'pending_amount', 'failed_amount', 'cancelled_amount',
            'authorized_amount',
        ]));
    }

    /** @param array<string,mixed> $filters */
    private function buildPage(array $filters, int $limit, int $offset): array
    {
        $total = $this->reconciliationRepository->count($filters);
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = $total === 0 ? 1 : min((int)floor($offset / $limit) + 1, $totalPages);
        $effectiveOffset = ($page - 1) * $limit;
        return [
            'items' => $this->reconciliationRepository->list($filters, $limit, $effectiveOffset),
            'total' => $total,
            'limit' => $limit,
            'offset' => $effectiveOffset,
            'page' => $page,
            'total_pages' => $totalPages,
        ];
    }

    /** @param array<string,mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        $paymentStatuses = array_keys(self::PAYMENT_STATUSES);
        $reconciliationStatuses = ['reconciled', 'divergent', 'pending', 'inconsistent'];
        $reasons = ['amount_mismatch', 'status_mismatch', 'orphan_transaction', 'missing_payment_transaction', 'unknown_payment_status'];

        foreach (['status', 'provider', 'date_from', 'date_to', 'search', 'reconciliation_status', 'divergence_reason'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null) {
                continue;
            }
            if (!is_string($filters[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = trim($filters[$key]);
            if ($value === '') {
                continue;
            }
            if ($key === 'status' && !in_array($value, $paymentStatuses, true)) {
                throw new InvalidArgumentException('Invalid payment status filter.');
            }
            if ($key === 'reconciliation_status' && !in_array($value, $reconciliationStatuses, true)) {
                throw new InvalidArgumentException('Invalid reconciliation status filter.');
            }
            if ($key === 'divergence_reason' && !in_array($value, $reasons, true)) {
                throw new InvalidArgumentException('Invalid divergence reason filter.');
            }
            $normalized[$key] = match ($key) {
                'provider' => substr($value, 0, 40),
                'search' => substr($value, 0, 120),
                default => $value,
            };
        }

        foreach (['customer_id', 'order_id'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
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

    private function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Invalid reconciliation idempotency key.');
        }
        return $key;
    }

    private function normalizeActor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 100) {
            throw new InvalidArgumentException('Invalid reconciliation actor.');
        }
        return $actor;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new InvalidArgumentException('Invalid reconciliation reason.');
        }
        return $reason;
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    /** @param array<string,mixed> $row */
    private function withClassification(array $row, string $status, string $reason): array
    {
        $row['reconciliation_status'] = $status;
        $row['divergence_reason'] = $reason;
        return $row;
    }
}
