<?php
declare(strict_types=1);

namespace App\Repositories;

interface PaymentTransactionRepositoryInterface
{
    /**
     * @param array<string, scalar|null> $filters
     * @return list<array<string, mixed>>
     */
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array;

    /**
     * @param array<string, scalar|null> $filters
     * @return array<string, int|float>
     */
    public function summarize(array $filters = []): array;
}
