<?php
declare(strict_types=1);

namespace App\Repositories;

interface PaymentTransactionRepositoryInterface
{
    public function findById(int $id, bool $forUpdate = false): ?array;

    public function findByExternalReference(string $externalReference, bool $forUpdate = false): ?array;

    public function updateStatus(int $id, string $status): bool;

    /**
     * Apply a validated Mercado Pago webhook transition and its dependent order/
     * stock effects using the caller's current database transaction.
     *
     * @return array{transaction_id:int,order_id:int,old_status:string,new_status:string}
     */
    public function applyWebhookTransition(int $id, string $providerPaymentId, string $newStatus): array;

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
