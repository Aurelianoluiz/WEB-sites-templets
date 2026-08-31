<?php
declare(strict_types=1);

namespace App\Repositories;

interface OrderRepositoryInterface
{
    public function findById(int $id, bool $forUpdate = false): ?array;
    public function findByReference(string $ref): ?array;
    public function findByIdAndUser(int $id, int $userId, bool $forUpdate = false): ?array;
    public function findByUserId(int $userId, int $limit = 50, int $offset = 0): array;
    public function findItemsByOrderId(int $orderId): array;
    public function updateStatus(int $id, string $status, ?string $paymentStatus = null): bool;
    public function recordStatusHistory(int $orderId, string $from, string $to, ?int $actorUserId, string $note = ''): bool;
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array;
    public function listAll(string $statusFilter = '', int $limit = 50, int $offset = 0): array;

    /**
     * @param array<string, scalar|null> $filters
     * @return list<array<string, mixed>>
     */
    public function listWithoutPaymentTransaction(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * @param array<string, scalar|null> $filters
     */
    public function countWithoutPaymentTransaction(array $filters = []): int;
}
