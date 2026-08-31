<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\OrderRepositoryInterface;
use App\Repositories\PaymentTransactionRepositoryInterface;
use App\Services\ReconciliationService;

final class ReconciliationServiceTestFailure extends RuntimeException
{
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ReconciliationServiceTestFailure($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new ReconciliationServiceTestFailure(
            $message
            . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new ReconciliationServiceTestFailure($message);
}

final class FakePaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    public array $rows;

    public int $listCalls = 0;
    public int $summaryCalls = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return list<array<string,mixed>>
     */
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array
    {
        $this->listCalls++;
        $filtered = array_values(array_filter(
            $this->rows,
            function (array $row) use ($filters): bool {
                if (isset($filters['status']) && $filters['status'] !== '' && $row['status'] !== $filters['status']) {
                    return false;
                }
                if (isset($filters['provider']) && $filters['provider'] !== '' && $row['provider'] !== $filters['provider']) {
                    return false;
                }
                if (isset($filters['customer_id']) && (int)($row['customer_id'] ?? 0) !== (int)$filters['customer_id']) {
                    return false;
                }
                return true;
            }
        ));

        usort($filtered, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);

        return array_slice($filtered, max(0, $offset), max(1, min(100, $limit)));
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array<string,int|float>
     */
    public function summarize(array $filters = []): array
    {
        $this->summaryCalls++;
        $rows = $this->listWithFilters($filters, 100, 0);

        $summary = [
            'count' => count($rows),
            'total' => 0.0,
            'paid' => 0.0,
            'refunded' => 0.0,
            'pending' => 0.0,
            'failed' => 0.0,
            'cancelled' => 0.0,
            'authorized' => 0.0,
        ];

        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $summary['total'] += $amount;
            $status = (string)$row['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status] += $amount;
            }
        }

        return $summary;
    }
}

final class FakeOrderRepository implements OrderRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    public array $missingOrders;

    public int $missingListCalls = 0;
    public int $missingCountCalls = 0;

    /** @param list<array<string,mixed>> $missingOrders */
    public function __construct(array $missingOrders)
    {
        $this->missingOrders = $missingOrders;
    }

    public function findById(int $id, bool $forUpdate = false): ?array { return null; }
    public function findByReference(string $ref): ?array { return null; }
    public function findByIdAndUser(int $id, int $userId, bool $forUpdate = false): ?array { return null; }
    public function findByUserId(int $userId, int $limit = 50, int $offset = 0): array { return []; }
    public function findItemsByOrderId(int $orderId): array { return []; }
    public function updateStatus(int $id, string $status, ?string $paymentStatus = null): bool { return false; }
    public function recordStatusHistory(int $orderId, string $from, string $to, ?int $actorUserId, string $note = ''): bool { return false; }
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array { return []; }
    public function listAll(string $statusFilter = '', int $limit = 50, int $offset = 0): array { return []; }

    /** @param array<string, scalar|null> $filters @return list<array<string,mixed>> */
    public function listWithoutPaymentTransaction(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->missingListCalls++;
        return array_slice($this->missingOrders, max(0, $offset), max(1, min(100, $limit)));
    }

    /** @param array<string, scalar|null> $filters */
    public function countWithoutPaymentTransaction(array $filters = []): int
    {
        $this->missingCountCalls++;
        return count($this->missingOrders);
    }
}

$paymentRows = [
    [
        'id' => 6,
        'order_id' => 106,
        'customer_id' => 1,
        'customer_name' => 'Cliente 6',
        'order_email' => 'c6@example.test',
        'amount' => 150.00,
        'status' => 'paid',
        'order_status' => 'confirmed',
        'order_payment_status' => 'paid',
        'order_total' => 150.00,
        'provider' => 'mercadopago',
    ],
    [
        'id' => 5,
        'order_id' => 105,
        'customer_id' => 1,
        'customer_name' => 'Cliente 5',
        'order_email' => 'c5@example.test',
        'amount' => 125.00,
        'status' => 'paid',
        'order_status' => 'confirmed',
        'order_payment_status' => 'paid',
        'order_total' => 100.00,
        'provider' => 'mercadopago',
    ],
    [
        'id' => 4,
        'order_id' => 104,
        'customer_id' => 1,
        'customer_name' => 'Cliente 4',
        'order_email' => 'c4@example.test',
        'amount' => 90.00,
        'status' => 'paid',
        'order_status' => 'cancelled',
        'order_payment_status' => 'paid',
        'order_total' => 90.00,
        'provider' => 'mercadopago',
    ],
    [
        'id' => 3,
        'order_id' => 103,
        'customer_id' => 1,
        'customer_name' => 'Cliente 3',
        'order_email' => 'c3@example.test',
        'amount' => 75.00,
        'status' => 'pending',
        'order_status' => 'pending',
        'order_payment_status' => 'pending',
        'order_total' => 75.00,
        'provider' => 'mercadopago',
    ],
    [
        'id' => 2,
        'order_id' => 102,
        'customer_id' => 2,
        'customer_name' => 'Cliente 2',
        'order_email' => 'c2@example.test',
        'amount' => 50.00,
        'status' => 'refunded',
        'order_status' => 'cancelled',
        'order_payment_status' => 'refunded',
        'order_total' => 50.00,
        'provider' => 'mercadopago',
    ],
    [
        'id' => 1,
        'order_id' => 9999,
        'customer_id' => 3,
        'customer_name' => 'Órfão',
        'order_email' => 'orphan@example.test',
        'amount' => 30.00,
        'status' => 'paid',
        'order_status' => null,
        'order_payment_status' => null,
        'order_total' => null,
        'provider' => 'mercadopago',
    ],
];

$missingOrders = [
    [
        'id' => 201,
        'customer_name' => 'Pedido sem pagamento',
        'user_email' => 'missing@example.test',
        'total' => 70.00,
        'status' => 'confirmed',
        'payment_status' => 'pending',
        'created_at' => '2026-08-31 10:00:00',
    ],
];

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$paymentRepository = new FakePaymentTransactionRepository($paymentRows);
$orderRepository = new FakeOrderRepository($missingOrders);
$service = new ReconciliationService($pdo, $paymentRepository, $orderRepository);

$perfect = $service->classifyPayment($paymentRows[0]);
assertSameValue('reconciled', $perfect['reconciliation_status'], 'Perfect match must be reconciled.');
assertSameValue('', $perfect['divergence_reason'], 'Perfect match must not have a divergence reason.');

$amountMismatch = $service->classifyPayment($paymentRows[1]);
assertSameValue('divergent', $amountMismatch['reconciliation_status'], 'Amount mismatch must be divergent.');
assertSameValue('amount_mismatch', $amountMismatch['divergence_reason'], 'Amount mismatch reason is incorrect.');

$statusMismatch = $service->classifyPayment($paymentRows[2]);
assertSameValue('divergent', $statusMismatch['reconciliation_status'], 'Status mismatch must be divergent.');
assertSameValue('status_mismatch', $statusMismatch['divergence_reason'], 'Status mismatch reason is incorrect.');

$pending = $service->classifyPayment($paymentRows[3]);
assertSameValue('pending', $pending['reconciliation_status'], 'Pending payment must remain pending.');

$orphan = $service->classifyPayment($paymentRows[5]);
assertSameValue('inconsistent', $orphan['reconciliation_status'], 'Orphan transaction must be inconsistent.');
assertSameValue('orphan_transaction', $orphan['divergence_reason'], 'Orphan transaction reason is incorrect.');

$summary = $service->getSummary();
assertSameValue(7, $summary['total'], 'Summary must include payment and missing-order candidates.');
assertSameValue(2, $summary['reconciled'], 'Reconciled count is incorrect.');
assertSameValue(2, $summary['divergent'], 'Divergent count is incorrect.');
assertSameValue(1, $summary['pending'], 'Pending count is incorrect.');
assertSameValue(2, $summary['inconsistent'], 'Inconsistent count is incorrect.');
assertSameValue(1, $summary['orphan_transactions'], 'Orphan transaction count is incorrect.');
assertSameValue(1, $summary['missing_transactions'], 'Missing transaction count is incorrect.');
assertSameValue(1, $summary['amount_mismatches'], 'Amount mismatch count is incorrect.');
assertSameValue(1, $summary['status_mismatches'], 'Status mismatch count is incorrect.');
assertSameValue(520.00, $summary['total_amount'], 'Payment amount summary is incorrect.');

$pageOne = $service->getPage([], 2, 0);
assertSameValue(2, count($pageOne['items']), 'First reconciliation page size is incorrect.');
assertSameValue(4, $pageOne['total_pages'], 'Total pages must include missing orders.');
assertSameValue(1, $pageOne['page'], 'First page number is incorrect.');

$pageTwo = $service->getPage([], 2, 2);
assertSameValue(2, count($pageTwo['items']), 'Second reconciliation page size is incorrect.');
assertSameValue(2, $pageTwo['page'], 'Second page number is incorrect.');

assertThrows(static fn (): array => $service->getPage([], 0, 0), 'Zero page size must be rejected.');
assertThrows(static fn (): array => $service->getPage([], 50, -1), 'Negative offset must be rejected.');
assertThrows(static fn (): array => $service->getPage(['date_from' => '2026-09-01', 'date_to' => '2026-08-01']), 'Reversed date range must be rejected.');

assertTrue($paymentRepository->summaryCalls > 0, 'Payment repository summary was not called.');
assertTrue($paymentRepository->listCalls > 0, 'Payment repository listing was not called.');
assertTrue($orderRepository->missingCountCalls > 0, 'Order repository missing-payment count was not called.');
assertTrue($orderRepository->missingListCalls > 0, 'Order repository missing-payment listing was not called.');

$serviceSource = (string)file_get_contents(__DIR__ . '/../src/Services/ReconciliationService.php');
assertTrue(!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b\s+(FROM|INTO|SET)?/i', $serviceSource), 'ReconciliationService must not contain SQL statements.');

$service->transaction(static function (PDO $db): void {
    $db->exec('CREATE TABLE probe (value TEXT NOT NULL)');
    $db->exec("INSERT INTO probe (value) VALUES ('committed')");
});
assertSameValue(1, (int)$pdo->query("SELECT COUNT(*) FROM probe WHERE value = 'committed'")->fetchColumn(), 'Committed transaction was not persisted.');

assertThrows(static function () use ($service): void {
    $service->transaction(static function (PDO $db): never {
        $db->exec("INSERT INTO probe (value) VALUES ('rolled-back')");
        throw new RuntimeException('forced rollback');
    });
}, 'Rollback must propagate the underlying exception.');
assertSameValue(0, (int)$pdo->query("SELECT COUNT(*) FROM probe WHERE value = 'rolled-back'")->fetchColumn(), 'Rolled-back transaction persisted data.');

$pdo->beginTransaction();
try {
    assertThrows(static fn (): mixed => $service->transaction(static fn (PDO $db): string => 'nested'), 'Nested reconciliation transactions must be rejected.');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "PASS: reconciliation_service_test\n";
