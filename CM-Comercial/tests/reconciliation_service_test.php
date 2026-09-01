<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentAuditRepositoryInterface;
use App\Repositories\PaymentTransactionRepositoryInterface;
use App\Repositories\ReconciliationRepositoryInterface;
use App\Services\ReconciliationService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReconciliationServiceTestFailure extends RuntimeException {}

function rsAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new ReconciliationServiceTestFailure($message);
    }
}

function rsSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new ReconciliationServiceTestFailure(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function rsThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new ReconciliationServiceTestFailure($message);
}

final class FakeReconciliationRepository implements ReconciliationRepositoryInterface
{
    public int $listCalls = 0;
    public int $summaryCalls = 0;
    public int $countCalls = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->listCalls++;
        return array_slice($this->filter($filters), max(0, $offset), max(1, min(100, $limit)));
    }

    public function summarize(array $filters = []): array
    {
        $this->summaryCalls++;
        $rows = $this->filter($filters);
        $summary = [
            'total' => count($rows),
            'count' => count($rows),
            'total_amount' => array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $rows)),
            'reconciled' => 0,
            'divergent' => 0,
            'pending' => 0,
            'inconsistent' => 0,
            'amount_mismatches' => 0,
            'status_mismatches' => 0,
            'orphan_transactions' => 0,
            'missing_transactions' => 0,
        ];
        foreach ($rows as $row) {
            $state = (string)($row['reconciliation_status'] ?? 'inconsistent');
            if (isset($summary[$state]) && is_int($summary[$state])) {
                $summary[$state]++;
            }
            $reason = (string)($row['divergence_reason'] ?? '');
            foreach (['amount_mismatches' => 'amount_mismatch', 'status_mismatches' => 'status_mismatch', 'orphan_transactions' => 'orphan_transaction', 'missing_transactions' => 'missing_payment_transaction'] as $key => $expected) {
                if ($reason === $expected) {
                    $summary[$key]++;
                }
            }
        }
        return $summary;
    }

    public function count(array $filters = []): int
    {
        $this->countCalls++;
        return count($this->filter($filters));
    }

    private function filter(array $filters): array
    {
        return array_values(array_filter($this->rows, static function (array $row) use ($filters): bool {
            foreach (['status', 'provider', 'customer_id', 'order_id', 'reconciliation_status', 'divergence_reason'] as $key) {
                if (isset($filters[$key]) && $filters[$key] !== '' && (string)($row[$key] ?? '') !== (string)$filters[$key]) {
                    return false;
                }
            }
            return true;
        }));
    }
}

final class FakePaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    public int $findCalls = 0;
    public int $updateCalls = 0;
    public ?string $updatedStatus = null;

    /** @param array<int,array<string,mixed>> $transactions */
    public function __construct(private array $transactions) {}

    public function findById(int $id, bool $forUpdate = false): ?array
    {
        $this->findCalls++;
        return $this->transactions[$id] ?? null;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $this->updateCalls++;
        if (!isset($this->transactions[$id])) {
            return false;
        }
        $this->transactions[$id]['status'] = $status;
        $this->updatedStatus = $status;
        return true;
    }

    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array { return []; }
    public function summarize(array $filters = []): array { return ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'refunded' => 0.0, 'pending' => 0.0, 'failed' => 0.0, 'cancelled' => 0.0, 'authorized' => 0.0]; }
    public function summarizeForReconciliation(array $filters = []): array { return []; }
}

final class FakePaymentAuditRepository implements PaymentAuditRepositoryInterface
{
    public int $eventChecks = 0;
    public int $resolutionCalls = 0;
    public ?string $lastKey = null;
    public array $processed = [];

    public function logEvent(array $data): int { return 1; }

    public function logResolution(int $transactionId, string $actor, string $oldStatus, string $newStatus, string $reason, ?string $idempotencyKey = null): bool
    {
        $this->resolutionCalls++;
        $this->lastKey = $idempotencyKey;
        if ($idempotencyKey !== null) {
            $this->processed[$idempotencyKey] = true;
        }
        return true;
    }

    public function getHistoryByTransactionId(int $transactionId): array { return []; }
    public function getHistoryByOrderId(int $orderId): array { return []; }
    public function listAuditLogs(array $filters, int $limit, int $offset): array { return []; }

    public function isEventProcessed(string $idempotencyKey): bool
    {
        $this->eventChecks++;
        return isset($this->processed[$idempotencyKey]);
    }

    public function transaction(callable $operation): mixed
    {
        return $operation();
    }
}

$rows = [
    ['transaction_id' => 1, 'order_id' => 10, 'customer_id' => 1, 'provider' => 'mercadopago', 'amount' => 100.00, 'payment_status' => 'paid', 'reconciliation_status' => 'reconciled', 'divergence_reason' => null],
    ['transaction_id' => 2, 'order_id' => 11, 'customer_id' => 1, 'provider' => 'mercadopago', 'amount' => 125.00, 'payment_status' => 'paid', 'reconciliation_status' => 'divergent', 'divergence_reason' => 'amount_mismatch'],
    ['transaction_id' => 3, 'order_id' => 12, 'customer_id' => 1, 'provider' => 'mercadopago', 'amount' => 90.00, 'payment_status' => 'paid', 'reconciliation_status' => 'divergent', 'divergence_reason' => 'status_mismatch'],
    ['transaction_id' => 4, 'order_id' => 13, 'customer_id' => 1, 'provider' => 'mercadopago', 'amount' => 75.00, 'payment_status' => 'pending', 'reconciliation_status' => 'pending', 'divergence_reason' => null],
    ['transaction_id' => 5, 'order_id' => 999, 'customer_id' => 2, 'provider' => 'mercadopago', 'amount' => 30.00, 'payment_status' => 'paid', 'reconciliation_status' => 'inconsistent', 'divergence_reason' => 'orphan_transaction'],
    ['transaction_id' => null, 'order_id' => 14, 'customer_id' => 3, 'provider' => null, 'amount' => 50.00, 'payment_status' => null, 'reconciliation_status' => 'inconsistent', 'divergence_reason' => 'missing_payment_transaction'],
];

$paymentTransactions = [
    2 => ['id' => 2, 'order_id' => 11, 'customer_id' => 1, 'amount' => 125.00, 'order_total_amount' => 100.00, 'status' => 'paid', 'order_status' => 'confirmed', 'order_payment_status' => 'pending'],
    3 => ['id' => 3, 'order_id' => 12, 'customer_id' => 1, 'amount' => 90.00, 'order_total_amount' => 90.00, 'status' => 'paid', 'order_status' => 'cancelled', 'order_payment_status' => 'paid'],
    4 => ['id' => 4, 'order_id' => 13, 'customer_id' => 1, 'amount' => 75.00, 'order_total_amount' => 75.00, 'status' => 'pending', 'order_status' => 'pending', 'order_payment_status' => 'pending'],
];

$reconciliationRepo = new FakeReconciliationRepository($rows);
$paymentRepo = new FakePaymentTransactionRepository($paymentTransactions);
$auditRepo = new FakePaymentAuditRepository();
$service = new ReconciliationService($reconciliationRepo, $paymentRepo, $auditRepo);

$summary = $service->getSummary();
rsSame(6, $summary['total'], 'Summary total failed.');
rsSame(2, $summary['divergent'], 'Divergent count failed.');
rsSame(2, $summary['inconsistent'], 'Inconsistent count failed.');

$page = $service->getPage([], 2, 0);
rsSame(2, count($page['items']), 'Page size failed.');
rsSame(3, $page['total_pages'], 'Total pages failed.');
rsSame(100, $service->getPage([], 500, 0)['limit'], 'Limit cap failed.');
rsThrows(static fn(): array => $service->getPage([], 0, 0), 'Zero limit must throw.');
rsThrows(static fn(): array => $service->getPage([], 50, -1), 'Negative offset must throw.');
rsThrows(static fn(): array => $service->getPage(['date_from' => '2026-09-01', 'date_to' => '2026-08-01']), 'Reversed date range must throw.');

$resolved = $service->resolveDivergence(2, 'admin@example.test', 'refunded', 'Manual financial correction', 'resolve-2');
rsSame(true, $resolved['success'], 'Divergence resolution failed.');
rsSame('paid', $resolved['old_status'], 'Resolution old status failed.');
rsSame('refunded', $resolved['new_status'], 'Resolution new status failed.');
rsSame(1, $paymentRepo->updateCalls, 'Payment status was not updated exactly once.');
rsSame('refunded', $paymentRepo->updatedStatus, 'Payment status was not changed to the requested status.');
rsSame(1, $auditRepo->resolutionCalls, 'Audit resolution was not recorded.');
rsSame('resolve-2', $auditRepo->lastKey, 'Resolution idempotency key was not passed to audit repository.');

$second = $service->resolveDivergence(2, 'admin@example.test', 'refunded', 'Duplicate submission', 'resolve-2');
rsSame(true, $second['idempotent'], 'Duplicate resolution was not treated as idempotent.');
rsSame(1, $paymentRepo->updateCalls, 'Duplicate resolution changed status twice.');
rsSame(1, $auditRepo->resolutionCalls, 'Duplicate resolution created a second audit event.');

rsThrows(static fn(): array => $service->resolveDivergence(4, 'admin@example.test', 'refunded', 'Status test', 'resolve-3'), 'Non-divergent resolution must be rejected.');
rsThrows(static fn(): array => $service->resolveDivergence(2, '', 'refunded', 'Reason', 'resolve-4'), 'Empty actor must be rejected.');
rsThrows(static fn(): array => $service->resolveDivergence(2, 'admin@example.test', 'invalid', 'Reason', 'resolve-5'), 'Invalid new status must be rejected.');

$source = (string)file_get_contents(__DIR__ . '/../src/Services/ReconciliationService.php');
rsAssert(!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+(?:FROM|INTO|SET|JOIN|WHERE)?/i', $source), 'ReconciliationService contains SQL.');
rsAssert(!str_contains($source, '->prepare('), 'ReconciliationService contains PDO access.');
rsAssert(str_contains($source, 'PaymentAuditRepositoryInterface'), 'Audit repository dependency is missing.');

echo "PASS: reconciliation_service_test\n";
