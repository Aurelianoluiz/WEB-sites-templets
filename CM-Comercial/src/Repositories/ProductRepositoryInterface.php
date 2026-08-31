<?php
declare(strict_types=1);

namespace App\Repositories;

interface ProductRepositoryInterface
{
    public function findById(int $id, bool $forUpdate = false): ?array;
    public function decrementStock(int $id, int $quantity): bool;
    public function incrementStock(int $id, int $quantity): bool;
    public function recordStockMovement(int $productId, string $type, int $qty, string $reason, ?int $userId = null): bool;
}
