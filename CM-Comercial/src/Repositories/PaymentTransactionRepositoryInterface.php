<?php
declare(strict_types=1);

namespace App\Repositories;

interface PaymentTransactionRepositoryInterface
{
    public function findById(int $id, bool $forUpdate = false): ?array;

    public function updateStatus(int $id, string $status): bool;

    /** @param array<string, scalar|null> $filters */
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array;

    /** @param array<string, scalar|null> $filters */
    public function summarize(array $filters = []): array;

    /**
     * Returns reconciliation-oriented aggregate counts for persisted payment
     * transactions matched against their orders.
     *
     * @param array<string, scalar|null> $filters
     */
    public function summarizeForReconciliation(array $filters = []): array;
}
