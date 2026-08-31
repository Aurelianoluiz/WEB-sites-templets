<?php
declare(strict_types=1);

namespace App\Repositories;

interface PaymentTransactionRepositoryInterface
{
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array;
}
