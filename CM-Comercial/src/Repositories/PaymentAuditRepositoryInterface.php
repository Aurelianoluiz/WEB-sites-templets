<?php
declare(strict_types=1);

namespace App\Repositories;

interface PaymentAuditRepositoryInterface
{
    /**
     * Persist a sanitized payment audit event.
     *
     * Supported fields:
     * - payment_transaction_id|transaction_id: positive integer
     * - event_type: non-empty string
     * - old_status: nullable string
     * - new_status: nullable string
     * - actor: non-empty string
     * - reason: nullable string
     * - payload: scalar/array values; sensitive keys are removed
     * - idempotency_key: nullable unique key
     *
     * @param array<string,mixed> $data
     */
    public function logEvent(array $data): int;

    public function logResolution(
        int $transactionId,
        string $actor,
        string $oldStatus,
        string $newStatus,
        string $reason,
        ?string $idempotencyKey = null
    ): bool;

    /** @return list<array<string,mixed>> */
    public function getHistoryByTransactionId(int $transactionId): array;

    /** @return list<array<string,mixed>> */
    public function getHistoryByOrderId(int $orderId): array;

    /** @param array<string,scalar|null> $filters @return list<array<string,mixed>> */
    public function listAuditLogs(array $filters, int $limit, int $offset): array;

    public function isEventProcessed(string $idempotencyKey): bool;

    /**
     * Execute repository-backed operations in one ACID transaction.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed;
}
