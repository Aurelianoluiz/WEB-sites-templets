<?php
declare(strict_types=1);

namespace App\Repositories;

interface ReconciliationRepositoryInterface
{
    /**
     * @param array<string, scalar|null> $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * @param array<string, scalar|null> $filters
     * @return array<string, int|float>
     */
    public function summarize(array $filters = []): array;

    /**
     * @param array<string, scalar|null> $filters
     */
    public function count(array $filters = []): int;
}
