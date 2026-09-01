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
            $message . ' expected=' . var_export($expected, true)
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
    public int $summaryCalls = 0;
    public int $reconciliationSummaryCalls = 0;
    public int $listCalls = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @param array<string,scalar|null> $filters */
    public function listWithFilters(array $filters, int $limit = 50, int $offset = 0): array
    {
        $this->listCalls++;
        $rows = $this->filteredRows($filters);
        usort($rows, static fn (array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
        return array_slice($rows, max(0, $offset), max(1, min(100, $limit)));
    }

    /** @param array<string,scalar|null> $filters */
    public function summarize(array $filters = []): array
    {
        $this->summaryCalls++;
        $rows = $this->filteredRows($filters);
        $result = [
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
            $result['total'] += $amount;
            $status = (string)$row['status'];
            if (array_key_exists($status, $result)) {
                $result[$status] += $amount;
            }
        }

        foreach ($result as $key => $value) {
            if (is_float($value)) {
                $result[$key] = round($value, 2);
            }
        }

        return $result;
    }

    /** @param array<string,scalar|null> $filters */
    public function summarizeForReconciliation(array $filters = []): array
    {
        $this->reconciliationSummaryCalls++;
        $rows = $this->filteredRows($filters);
        $result = [
            'total' => count($rows),
            'reconciled' => 0,
            'divergent' => 0,
            'pending' => 0,
            'inconsistent' => 0,
            'orphan_transactions' => 0,
            'amount_mismatches' => 0,
            'status_mismatches' => 0,
            'total_amount' => 0.0,
            'paid' => 0.0,
            'refunded' => 0.0,
            'pending_amount' => 0.0,
            'failed' => 0.0,
            'cancelled' => 0.0,
            'authorized' => 0.0,
        ];

        foreach ($rows as $row) {
            $result['total_amount'] += (float)$row['amount'];
            $status = (string)$row['status'];
            if (isset($result[$status]) && is_float($result[$status])) {
                $result[$status] += (float)$row['amount'];
            }

            if (($row['order_status'] ?? null) === null) {
                $result['inconsistent']++;
                $result['orphan_transactions']++;
                continue;
            }

            if (abs((float)$row['amount'] - (float)($row['order_total'] ?? 0)) > 0.01) {
                $result['divergent']++;
                $result['amount_mismatches']++;
                continue;
            }

            $paymentStatus = (string)$row['status'];
            $orderPaymentStatus = (string)($row['order_payment_status'] ?? '');
            $accepted = $paymentStatus === 'authorized'
                ? ['authorized', 'pending']
                : ($paymentStatus === 'cancelled' ? ['cancelled', 'failed'] : [$paymentStatus]);

            if ($orderPaymentStatus !== '' && !in_array($orderPaymentStatus, $accepted, true)) {
                $result['divergent']++;
                $result['status_mismatches']++;
                continue;
            }

            if (in_array($paymentStatus, ['pending', 'authorized'], true)) {
                $result['pending']++;
                continue;
            }

            $result['reconciled']++;
        }

        $result['total_amount'] = round($result['total_amount'], 2);
        foreach (['paid', 'refunded', 'pending_amount', 'failed', 'cancelled', 'authorized'] as $key) {
            $result[$key] = round((float)$result[$key], 2);
        }

        return $result;
    }

    /** @param array<string,scalar|null> $filters @return list<array<string,mixed>> */
    private function filteredRows(array $filters): array
    {
        return array_values(array_filter(
            $this->rows,
            static function (array $row) use ($filters): bool {
                foreach (['status', 'provider'] as $key) {
                    if (isset($filters[$key]) && $filters[$key] !== '' && $row[$key] !== $filters[$key]) {
                        return false;
                    }
                }
                if (isset($filters['customer_id']) && (int)$row['customer_id'] !== (int)$filters['customer_id']) {
                    return false;
                }
                if (isset($filters['order_id']) && (int)$row['order_id'] !== (int)$filters['order_id']) {
                    return false;
                }
                return true;
            }
        ));
    }
}

final class FakeOrderRepository implements OrderRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    public array $missingOrders;
    public int $missingCountCalls = 0;
    public int $missingListCalls = 0;

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

    /** @param array<string,scalar|null> $filters */
    public function listWithoutPaymentTransaction(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->missingListCalls++;
        $rows = $this->missingOrders;
        if (isset($filters['customer_id'])) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int)($row['customer_id'] ?? 0) === (int)$filters['customer_id']));
        }
        if (isset($filters['order_id'])) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int)$row['id'] === (int)$filters['order_id']));
        }
        return array_slice($rows, max(0, $offset), max(1, min(100, $limit)));
    }

    /** @param array<string,scalar|null> $filters */
    public function countWithoutPaymentTransaction(array $filters = []): int
    {
        $this->missingCountCalls++;
        return count($this->listWithoutPaymentTransaction($filters, 100, 0));
    }
}

$paymentRows = [
    ['id' => 6, 'order_id' => 106, 'customer_id' => 1, 'customer_name' => 'Cliente 6', 'amount' => 150.00, 'status' => 'paid', 'order_status' => 'confirmed', 'order_payment_status' => 'paid', 'order_total' => 150.00, 'provider' => 'mercadopago'],
    ['id' => 5, 'order_id' => 105, 'customer_id' => 1, 'customer_name' => 'Cliente 5', 'amount' => 125.00, 'status' => 'paid', 'order_status' => 'confirmed', 'order_payment_status' => 'paid', 'order_total' => 100.00, 'provider' => 'mercadopago'],
    ['id' => 4, 'order_id' => 104, 'customer_id' => 1, 'customer_name' => 'Cliente 4', 'amount' => 90.00, 'status' => 'paid', 'order_status' => 'cancelled', 'order_payment_status' => 'pending', 'order_total' => 90.00, 'provider' => 'mercadopago'],
    ['id' => 3, 'order_id' => 103, 'customer_id' => 1, 'customer_name' => 'Cliente 3', 'amount' => 75.00, 'status' => 'pending', 'order_status' => 'pending', 'order_payment_status' => 'pending', 'order_total' => 75.00, 'provider' => 'mercadopago'],
    ['id' => 2, 'order_id' => 102, 'customer_id' => 2, 'customer_name' => 'Cliente 2', 'amount' => 50.00, 'status' => 'refunded', 'order_status' => 'cancelled', 'order_payment_status' => 'refunded', 'order_total' => 50.00, 'provider' => 'mercadopago'],
    ['id' => 1, 'order_id' => 9999, 'customer_id' => 3, 'customer_name' => 'Órfão', 'amount' => 30.00, 'status' => 'paid', 'order_status' => null, 'order_payment_status' => null, 'order_total' => null, 'provider' => 'mercadopago'],
];

$missingOrders = [
    ['id' => 201, 'customer_id' => 4, 'customer_name' => 'Pedido sem pagamento', 'user_email' => 'missing@example.test', 'total' => 70.00, 'status' => 'confirmed', 'payment_status' => 'pending', 'created_at' => '2026-08-31 10:00:00'],
];

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$payments = new FakePaymentTransactionRepository($paymentRows);
$orders = new FakeOrderRepository($missingOrders);
$service = new ReconciliationService($pdo, $payments, $orders);

assertSameValue('reconciled', $service->classifyPayment($paymentRows[0])['reconciliation_status'], 'Perfect match classification failed.');
assertSameValue('amount_mismatch', $service->classifyPayment($paymentRows[1])['divergence_reason'], 'Amount mismatch classification failed.');
assertSameValue('status_mismatch', $service->classifyPayment($paymentRows[2])['divergence_reason'], 'Status mismatch classification failed.');
assertSameValue('orphan_transaction', $service->classifyPayment($paymentRows[5])['divergence_reason'], 'Orphan classification failed.');

$summary = $service->getSummary();
assertSameValue(7, $summary['total'], 'Summary total must include payment and missing-order candidates.');
assertSameValue(2, $summary['reconciled'], 'Summary reconciled count is incorrect.');
assertSameValue(2, $summary['divergent'], 'Summary divergent count is incorrect.');
assertSameValue(1, $summary['pending'], 'Summary pending count is incorrect.');
assertSameValue(2, $summary['inconsistent'], 'Summary inconsistent count is incorrect.');
assertSameValue(1, $summary['orphan_transactions'], 'Summary orphan count is incorrect.');
assertSameValue(1, $summary['missing_transactions'], 'Summary missing transaction count is incorrect.');

$pageMissing = $service->getPage([], 2, 6);
assertSameValue(4, $pageMissing['page'], 'Missing-payment page number is incorrect.');
assertSameValue(1, count($pageMissing['items']), 'Missing-payment page must contain one candidate.');
assertSameValue('missing_payment_transaction', $pageMissing['items'][0]['divergence_reason'], 'Missing-payment candidate was not classified.');
assertTrue($orders->missingListCalls > 0, 'Order repository list was not used for missing transactions.');

$filtered = $service->getPage(['customer_id' => 1, 'provider' => 'mercadopago'], 2, 0);
assertSameValue(4, $filtered['total'], 'Customer/provider filter count is incorrect.');
assertSameValue(2, count($filtered['items']), 'Filtered page size is incorrect.');

$bounded = $service->getPage([], 500, 0);
assertSameValue(100, $bounded['limit'], 'Pagination limit must be capped at 100.');
assertThrows(static fn (): array => $service->getPage([], 0, 0), 'Zero limit must be rejected.');
assertThrows(static fn (): array => $service->getPage([], 50, -1), 'Negative offset must be rejected.');
assertThrows(static fn (): array => $service->getPage(['date_from' => '2026-09-01', 'date_to' => '2026-08-01']), 'Reversed date range must be rejected.');

assertTrue($payments->summaryCalls > 0, 'Payment repository summary was not called.');
assertTrue($payments->listCalls > 0, 'Payment repository listing was not called.');
assertTrue($payments->reconciliationSummaryCalls > 0, 'Reconciliation repository summary was not called.');
assertTrue($orders->missingCountCalls > 0, 'Order repository missing-payment count was not called.');

$commitResult = $service->transaction(static function (PDO $db): string {
    $db->exec('CREATE TABLE reconciliation_probe (value TEXT NOT NULL)');
    $db->exec("INSERT INTO reconciliation_probe (value) VALUES ('committed')");
    return 'committed';
});
assertSameValue('committed', $commitResult, 'Transaction commit result is incorrect.');
assertSameValue(1, (int)$pdo->query("SELECT COUNT(*) FROM reconciliation_probe WHERE value = 'committed'")->fetchColumn(), 'Commit did not persist.');

assertThrows(static function () use ($service): void {
    $service->transaction(static function (PDO $db): never {
        $db->exec("INSERT INTO reconciliation_probe (value) VALUES ('rolled-back')");
        throw new RuntimeException('forced rollback');
    });
}, 'Rollback must propagate the original exception.');
assertSameValue(0, (int)$pdo->query("SELECT COUNT(*) FROM reconciliation_probe WHERE value = 'rolled-back'")->fetchColumn(), 'Rollback did not undo the change.');

$first = $service->reconcile('same-key', [], 2, 0);
$payments->rows[0]['amount'] = 9999.00;
$second = $service->reconcile('same-key', [], 2, 0);
assertSameValue($first, $second, 'Duplicate reconciliation must be idempotent within the process.');

$serviceSource = (string)file_get_contents(__DIR__ . '/../src/Services/ReconciliationService.php');
assertTrue(!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b\s+(FROM|INTO|SET|WHERE)/i', $serviceSource), 'ReconciliationService must not contain SQL.');
assertTrue(str_contains($serviceSource, 'beginTransaction'), 'Transaction boundary is missing.');
assertTrue(str_contains($serviceSource, 'PaymentTransactionRepositoryInterface'), 'Payment repository dependency is missing.');
assertTrue(str_contains($serviceSource, 'OrderRepositoryInterface'), 'Order repository dependency is missing.');

echo "PASS: reconciliation_service_test\n";
