<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReconciliationRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ReconciliationService
{
    private const MAX_PAGE_SIZE = 100;
    private const DEFAULT_PAGE_SIZE = 50;

    /** @var array<string, array{summary:array<string,int|float>,page:array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,page:int,total_pages:int}}> */
    private array $idempotencyCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly ReconciliationRepositoryInterface $repository
    ) {
    }

    /** @param array<string,mixed> $filters */
    public function reconcile(string $idempotencyKey, array $filters = [], int $limit = self::DEFAULT_PAGE_SIZE, int $offset = 0): array
    {
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Invalid reconciliation idempotency key.');
        }
        $filters = $this->normalizeFilters($filters);
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        $cacheKey = hash('sha256', $key);
        if (isset($this->idempotencyCache[$cacheKey])) {
            return $this->idempotencyCache[$cacheKey];
        }
        $snapshot = $this->transaction(fn (): array => [
            'summary' => $this->repository->summarize($filters),
            'page' => $this->buildPage($filters, $limit, $offset),
        ]);
        $this->idempotencyCache[$cacheKey] = $snapshot;
        return $snapshot;
    }

    /** @param array<string,mixed> $filters */
    public function getPage(array $filters = [], int $limit = self::DEFAULT_PAGE_SIZE, int $offset = 0): array
    {
        $filters = $this->normalizeFilters($filters);
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        return $this->transaction(fn (): array => $this->buildPage($filters, $limit, $offset));
    }

    /** @param array<string,mixed> $filters */
    public function getSummary(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        return $this->transaction(fn (): array => $this->repository->summarize($filters));
    }

    /** @template T @param callable(PDO):T $operation @return T */
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

    /** @param array<string,mixed> $filters */
    private function buildPage(array $filters, int $limit, int $offset): array
    {
        $total = $this->repository->count($filters);
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = $total === 0 ? 1 : min((int)floor($offset / $limit) + 1, $totalPages);
        $effectiveOffset = ($page - 1) * $limit;
        return [
            'items' => $this->repository->list($filters, $limit, $effectiveOffset),
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
        $paymentStatuses = ['pending','authorized','paid','failed','cancelled','refunded'];
        $reconciliationStatuses = ['reconciled','divergent','pending','inconsistent'];
        $reasons = ['amount_mismatch','status_mismatch','orphan_transaction','missing_payment_transaction','unknown_payment_status'];
        $normalized = [];

        foreach (['status','provider','date_from','date_to','search','reconciliation_status','divergence_reason'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null) continue;
            if (!is_string($filters[$key])) throw new InvalidArgumentException("Invalid {$key} filter.");
            $value = trim($filters[$key]);
            if ($value === '') continue;
            if ($key === 'status' && !in_array($value, $paymentStatuses, true)) throw new InvalidArgumentException('Invalid payment status filter.');
            if ($key === 'reconciliation_status' && !in_array($value, $reconciliationStatuses, true)) throw new InvalidArgumentException('Invalid reconciliation status filter.');
            if ($key === 'divergence_reason' && !in_array($value, $reasons, true)) throw new InvalidArgumentException('Invalid divergence reason filter.');
            $normalized[$key] = $key === 'provider' ? substr($value, 0, 40) : ($key === 'search' ? substr($value, 0, 120) : $value);
        }

        foreach (['customer_id','order_id'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') continue;
            if (!is_int($filters[$key]) && !ctype_digit((string)$filters[$key])) throw new InvalidArgumentException("Invalid {$key} filter.");
            $value = (int)$filters[$key];
            if ($value < 1) throw new InvalidArgumentException("Invalid {$key} filter.");
            $normalized[$key] = $value;
        }

        foreach (['date_from','date_to'] as $key) {
            if (isset($normalized[$key]) && !$this->isValidDate((string)$normalized[$key])) throw new InvalidArgumentException("Invalid {$key} filter. Expected Y-m-d.");
        }
        if (isset($normalized['date_from'], $normalized['date_to']) && $normalized['date_from'] > $normalized['date_to']) throw new InvalidArgumentException('date_from cannot be greater than date_to.');
        return $normalized;
    }

    /** @return array{0:int,1:int} */
    private function normalizePagination(int $limit, int $offset): array
    {
        if ($limit < 1) throw new InvalidArgumentException('Pagination limit must be greater than zero.');
        if ($offset < 0) throw new InvalidArgumentException('Pagination offset cannot be negative.');
        return [min(self::MAX_PAGE_SIZE, $limit), $offset];
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false && $parsed->format('Y-m-d') === $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
