<?php
declare(strict_types=1);

namespace App\Repositories;

interface PaymentTransactionRepositoryInterface
{
    /** @param array<string, scalar|null> $filters */
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array;

    /** @param array<string, scalar|null> $filters */
    public function summarize(array $filters = []): array;

    /** @param array<string, scalar|null> $filters */
    public function listReconciliationCandidates(array $filters = [], int $limit = 50, int $offset = 0): array;

    /** @param array<string, scalar|null> $filters */
    public function countReconciliationCandidates(array $filters = []): int;

    /** @param array<string, scalar|null> $filters */
    public function summarizeReconciliation(array $filters = []): array;
}
